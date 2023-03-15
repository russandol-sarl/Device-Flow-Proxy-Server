<?php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Controller {
  const MSG_VER_ERROR = 'version_mismatch';
  const MSG_VER_ERROR_LONG = 'Votre version du plugin est trop ancienne, veuillez la mettre à jour';

  private function error(Response $response, $error, $error_description=false, $errno=400) {
    $data = [
      'error' => $error
    ];
    if($error_description) {
      $data['error_description'] = $error_description;
    }

    $response->setStatusCode($errno);
    $response->setContent($this->_json($data));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  private function html_error(Request $request, Response $response, $error, $error_description, $errno = 400) {
    $response->setStatusCode($errno);
    $response->setContent(view('error', [
      'error' => $error,
      'error_description' => $error_description,
      # 'request' => $request,
      'base_url' => $request->getBaseUrl()
    ]));
    return $response;
  }

  private function success(Response $response, $data) {
    $response->setContent($this->_json($data));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  # Home Page
  public function index(Request $request, Response $response) {
    $response->setContent(view('index', [
      'base_url' => $request->getBaseUrl()
    ]));
    return $response;
  }

  # Check version of env file if defined against user agent header
  public function checkVersion(Request $request) {
    $versionMin = getenv('VERSION_MIN');
    if($versionMin) {
      $userAgent = $request->headers->get('User-Agent');
      $pieces = explode('/', $userAgent);
      # check version
      if (count($pieces) > 1) {
          $version = $pieces[1];
          if (version_compare($version, $versionMin) >= 0) {
              return true;
          }
      }
      return false;
    }
    else {
      return true;
    }
  }
  
  # A device submits a request here to generate a new device and user code
  public function generate_code(Request $request, Response $response) {
    # Params:
    # client_id
    # scope

    if (!$this->checkVersion($request)) {
      return $this->error($response, self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }
    
    # client_id is required
    $client_id = $request->get('client_id');
    if($client_id == null) {
      return $this->error($response, 'invalid_request', 'Missing client_id');
    }

    # We've validated everything we can at this stage.
    # Generate a verification code and cache it along with the other values in the request.
    $device_code = bin2hex(random_bytes(32));
    # Generate a PKCE code_verifier and store it in the cache too
    $pkce_verifier = bin2hex(random_bytes(32));
    $cache = [
      'client_id' => $client_id,
      'client_secret' => $request->get('client_secret'),
      'scope' => $request->get('scope'),
      'device_code' => $device_code,
      'pkce_verifier' => $pkce_verifier,
    ];
    $user_code = random_alpha_string(4).'-'.random_alpha_string(4);
    Cache::set(str_replace('-', '', $user_code), $cache, 300); # store without the hyphen

    # Add a placeholder entry with the device code so that the token route knows the request is pending
    Cache::set($device_code, [
      'timestamp' => time(),
      'status' => 'pending'
    ], 300);

    $data = [
      'device_code' => $device_code,
      'user_code' => $user_code,
      'verification_uri' => getenv('BASE_URL') . '/device',
      'expires_in' => 300,
      'interval' => round(60/getenv('LIMIT_REQUESTS_PER_MINUTE'))
    ];

    return $this->success($response, $data);
  }

  # The user visits this page in a web browser
  # This interface provides a prompt to enter a device code, which then begins the actual OAuth flow
  public function device(Request $request, Response $response) {
    $response->setContent(view('device', [
      'code' => $request->get('code'),
      'state' => $request->get('state'),
      'base_url' => $request->getBaseUrl()
    ]));
    return $response;
  }

  # The browser submits a form that is a GET request to this route, which verifies
  # and looks up the user code, and then redirects to the real authorization server
  public function verify_code(Request $request, Response $response) {
    if($request->get('code') == null) {
      return $this->html_error($request, $response, 'invalid_request', 'Aucun code n\'a été entré');
    }

    $user_code = $request->get('code');
    # Remove hyphens and convert to uppercase to make it easier for users to enter the code
    $user_code = strtoupper(str_replace('-', '', $user_code));

    $cache = Cache::get($user_code);
    if(!$cache) {
      return $this->html_error($request, $response, 'invalid_request', 'Code non valide');
    }

    $state = bin2hex(random_bytes(16));

    $get_state = $request->get('state');
    if(!empty($get_state)) {
      $state .= $get_state;
    }

    Cache::set('state:'.$state, [
      'user_code' => $user_code,
      'timestamp' => time(),
    ], 300);

    // TODO: might need to make this configurable to support OAuth servers that have
    // custom parameters for the auth endpoint
    $query = [
      'response_type' => 'code',
      'client_id' => $cache->client_id,
      'state' => $state,
      'duration' => getenv('DURATION'),
    ];
    $redirect_uri = getenv('REDIRECT_URI');
    if ($redirect_uri) {
      $query['redirect_uri'] = $redirect_uri;
    }
    if($cache->scope) {
      $query['scope'] = $cache->scope;
    }
    if(getenv('PKCE')) {
      $pkce_challenge = base64_urlencode(hash('sha256', $cache->pkce_verifier, true));
      $query['code_challenge'] = $pkce_challenge;
      $query['code_challenge_method'] = 'S256';
    }

    $authURL = getenv('AUTHORIZATION_ENDPOINT') . '?' . http_build_query($query);

    $response->setStatusCode(302);
    $response->headers->set('Location', $authURL);
    return $response;
  }

  # After the user logs in and authorizes the app on the real auth server, they will
  # be redirected back to here. We'll need to exchange the auth code for an access token,
  # and then show a message that instructs the user to go back to their TV and wait.
  public function redirect(Request $request, Response $response) {
    # Check if error
    $error = $request->get('error');
    if($error) {
      return $this->html_error($request, $response, $error, $request->get('error_description'));
    }

    $get_state = $request->get('state');
    # Verify input params
    if($get_state == false || $request->get('code') == false) {
      return $this->html_error($request, $response, 'Invalid Request', 'Des paramètres manquent dans la requête');
    }

    # Check that the state parameter matches
    if(!($state=Cache::get('state:'.$get_state))) {
      return $this->html_error($request, $response, 'Invalid State', 'Le paramètre state n\'est pas valide');
    }

    # if state ends with '-cg', the call comes initially from recent plugin versions which wants us to use client credentials between this server and Enedis
    if (str_ends_with($get_state, '-cg')) {
      $usage_points_id = $request->get('$usage_point_id');
      $usage_points_id_tab = explore(',', $usage_points_id);
      if($usage_point_id == false) {
        return $this->html_error($request, $response, 'Invalid Request', 'Le paramètre usage_point_id manque dans la requête');
      }
      $access_token = new stdClass();
      $access_token->access_token = bin2hex(random_bytes(32));
      $access_token->token_type = 'Bearer';
      $access_token->expires_in = '12600';
      $access_token->refresh_token = bin2hex(random_bytes(32));
      $access_token->usage_points_id = $usage_points_id;
      $access_token->scope = '';
      foreach($usage_points_id_tab as $one_usage_point_id) {
        Cache::set('usage_point_access_token:'.$one_usage_point_id, $access_token->access_token, 12600);
        Cache::set('usage_point_refresh_token:'.$one_usage_point_id, $access_token->refresh_token, 4*365*24*60*60);
      }
      Cache::set($cache->device_code, [
        'status' => 'complete',
        'token_response' => $access_token
      ], 120);
      Cache::delete($state->user_code);
    }
    else {
      # Look up the info from the user code provided in the state parameter
      $cache = Cache::get($state->user_code);

      # Exchange the authorization code for an access token

      # TODO: Might need to provide a way to customize this request in case of
      # non-standard OAuth 2 services

      $params = [
        'grant_type' => 'authorization_code',
        'code' => $request->get('code'),
        'client_id' => $cache->client_id,
      ];
      $redirect_uri = getenv('REDIRECT_URI');
      if ($redirect_uri) {
        $params['redirect_uri'] = $redirect_uri;
      }
      $envSecret = getenv('CLIENT_SECRET');
      if($envSecret) {
        $params['client_secret'] = $envSecret;
      }
      elseif($cache->client_secret) {
        $params['client_secret'] = $cache->client_secret;
      }
      if(getenv('PKCE')) {
        $params['code_verifier'] = $cache->pkce_verifier;
      }

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, getenv('TOKEN_ENDPOINT'));
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $token_response = curl_exec($ch);
      $access_token = json_decode($token_response);

      if(!$access_token || !property_exists($access_token, 'access_token')) {
        # If there are any problems getting an access token, kill the request and display an error
        Cache::delete($state->user_code);
        Cache::delete($cache->device_code);
        return $this->html_error($request, $response, 'Error Logging In', 'Il y a eu une erreur en essayant d\'obtenir un jeton d\'accès du service <p><pre>'.$token_response.'</pre></p>');
      }

      // pass other parameters as json attributes
      foreach ($request->request as $key => $value) {
        if (($key != "state") and ($key != 'code')) {
          $access_token->$key = $value;
        }
      }
      foreach ($request->query as $key => $value) {
        if (($key != "state") and ($key != 'code')) {
          $access_token->$key = $value;
        }
      }

      # Stash the access token in the cache and display a success message
      Cache::set($cache->device_code, [
        'status' => 'complete',
        'token_response' => $access_token
      ], 120);
      Cache::delete($state->user_code);

      $response->setContent(view('signed-in', [
        'base_url' => $request->getBaseUrl()
      ]));
      return $response;
    }
  }

  private static $headers;
  
  public function resetHeaders() {
    self::$headers = [];
  }
  
  public function setHeader($curl, $header) {
    $len = strlen($header);
    $header = explode(':', $header, 2);
    if (count($header) < 2) // ignore invalid headers
      return $len;

    self::$headers[strtolower(trim($header[0]))][] = trim($header[1]);

    return $len;
  }
  
  # Proxy to TOKEN_ENDPOINT
  public function proxy(Request $request, Response $response) {
    if (!$this->checkVersion($request)) {
      return $this->error($response, self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }
    
    $params = $request->request->all();
  
    $envSecret = getenv('CLIENT_SECRET');
    if($envSecret) {
      $params['client_secret'] = $envSecret;
    }

    $redirect_uri = getenv('REDIRECT_URI');
    if ($redirect_uri) {
        $query = [
          'redirect_uri' => $redirect_uri,
        ];
        $tokenURL = getenv('TOKEN_ENDPOINT') . '?' . http_build_query($query);
    }
    else {
        $tokenURL = getenv('TOKEN_ENDPOINT');
    }
    
    self::resetHeaders();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenURL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // this function is called by curl for each header received
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'Controller::setHeader');

    $token_response = curl_exec($ch);
    
    if (array_key_exists('content-type', self::$headers)) {
      $response->headers->set('content-type', self::$headers['content-type']);
    }
    
    $response->setContent($token_response);
    return $response;
  }

  # Meanwhile, the device is continually posting to this route waiting for the user to
  # approve the request. Once the user approves the request, this route returns the access token.
  # In addition to the standard OAuth error responses defined in https://tools.ietf.org/html/rfc6749#section-4.2.2.1
  # the server should return: authorization_pending and slow_down
  public function access_token(Request $request, Response $response) {
    if (!$this->checkVersion($request)) {
      return $this->error($response, self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }

    $client_id = $request->get('client_id');
    if($client_id == null || $request->get('grant_type') == null) {
      return $this->error($response, 'invalid_request');
    }

    # This server supports the device_code response type
    if($request->get('grant_type') == 'urn:ietf:params:oauth:grant-type:device_code') {
      if($request->get('device_code') == null) {
        return $this->error($response, 'invalid_request');
      }
      $device_code = $request->get('device_code');

      #####################
      ## RATE LIMITING

      # Count the number of requests per minute
      $bucket = 'ratelimit-'.floor(time()/60).'-'.$device_code;

      if(Cache::get($bucket) >= getenv('LIMIT_REQUESTS_PER_MINUTE')) {
        return $this->error($response, 'slow_down');
      }

      # Mark for rate limiting
      Cache::incr($bucket);
      Cache::expire($bucket, 60);
      #####################

      # Check if the device code is in the cache
      $data = Cache::get($device_code);

      if(!$data) {
        return $this->error($response, 'invalid_grant');
      }

      if($data && $data->status == 'pending') {
        return $this->error($response, 'authorization_pending');
      } else if($data && $data->status == 'complete') {
        # return the raw access token response from the real authorization server
        Cache::delete($device_code);
        return $this->success($response, $data->token_response);
      } else {
        return $this->error($response, 'invalid_grant');
      }
    }
    // To test:
    // curl -X POST url/device/token -H 'Content-Type: application/x-www-form-urlencoded' -d 'grant_type=refresh_token&client_id=xxxx'
    elseif($request->get('grant_type') == 'refresh_token') {
      if($client_id != getenv('CLIENT_ID')) {
        return $this->error($response, 'invalid_request', 'Bad client_id');
      }
      $usage_points_id = $request->get('usage_point_id');
      if($usage_points_id == null) {
        return $this->error($response, 'invalid_request', 'Missing usage_point_id');
      }

      #####################
      ## RATE LIMITING

      $cip = $request->getClientIp();
      # Count the number of requests per minute
      $bucket = 'ratelimit-'.floor(time()/60).'-ip-'.$cip;

      if(Cache::get($bucket) >= getenv('LIMIT_REQUESTS_PER_MINUTE')) {
        return $this->error($response, 'slow_down');
      }

      # Mark for rate limiting
      Cache::incr($bucket);
      Cache::expire($bucket, 60);
      #####################

      $usage_points_id_tab = explode(',', $usage_points_id);
      $new_access_token = bin2hex(random_bytes(32));
      foreach($usage_points_id_tab as $one_usage_point_id) {
        # Check if the refresh_token is in the cache
        $old_refresh_token = Cache::get('usage_point_refresh_token:'.$one_usage_point_id);
        if (!$old_refresh_token) {
          return $this->error($response, 'Unauthorized', 'refresh_token not found in database', 404);
        }
        if ($old_refresh_token != $refresh_token) {
          return $this->error($response, 'Unauthorized', 'Bad refresh_token', 404);
        }
        Cache::set('usage_point_access_token:'.$one_usage_point_id, $new_access_token, 12600);
      }

      if($old_refresh_token) {
        $access_token = new stdClass();
        $access_token->access_token = $new_access_token;
        $access_token->token_type = 'Bearer';
        $access_token->expires_in = '12600';
        $access_token->refresh_token = $old_refresh_token;
        $access_token->scope = '';
        $response->setContent($access_token);
        return $response;
      }
      else {
        return $this->error($response, 'invalid_request', 'usage_points_id empty or corrupted');
      }
    }
    else {
      return $this->error($response, 'unsupported_grant_type', 'Only \'urn:ietf:params:oauth:grant-type:device_code\' and refresh_token are supported.');
    }
  }

  # renew client_credentials
  private function refresh_client_credentials(){
    $envId = getenv('CLIENT_ID');
    $envSecret = getenv('CLIENT_SECRET');
    $params = [
      'grant_type' => 'client_credentials',
      'client_id' => $envId,
      'client_secret' => $envSecret,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, getenv('TOKEN_ENDPOINT_V3'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'));
    $token_response = curl_exec($ch);
    $access_token = json_decode($token_response);

    if(!$access_token || !property_exists($access_token, 'access_token') || !property_exists($access_token, 'expires_in')) {
      # If there are any problems getting an access token, kill the request and display an error
      return null;
    }
    $expires_in = intval($access_token->expires_in);
    if ($expires_in > 180) {
      Cache::set('client_credentials', $access_token, $expires_in);
    }
    return $access_token;
  }

  # get json data with cURL
  private function get_data($path, $cg, $query){
    $query2 = http_build_query(array_slice($query->all(), 1));

    self::resetHeaders();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, getenv('DATA_ENDPOINT') . '/' . $path. '?' . $query2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'Controller::setHeader');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: ' . $cg->token_type . ' '. $cg->access_token, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'));
    $data = curl_exec($ch);
    $html_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    return array($errno, $html_code, $data);
  }

  # Proxy to data
  public function proxy_data(Request $request, Response $response, $vars) {
    if (!$this->checkVersion($request)) {
      return $this->error($response, self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }

    $path = $vars['path'];
    if($path == null) {
      return $this->error($response, 'invalid_request', 'path empty');
    }

    $usage_point_id = $request->get('usage_point_id', 'usage_point_id missing');
    if($usage_point_id == null) {
      return $this->error($response, 'invalid_request');
    }

    if(!getenv('DISABLE_DATA_ENPOINT_AUTH')) {
      $auth = $request->headers->get('Authorization');
      if(!$auth) {
        return $this->error($response, 'Unauthorized', 'autorization missing', 404);
      }
      $token = Cache::get('usage_point_access_token:'.$usage_point_id);
      $refresh = Cache::get('usage_point_refresh_token:'.$usage_point_id);
      if(!$token and !$refresh) {
        return $this->error($response, 'Unauthorized', 'no tokens in database', 404);
      }
      if(!$token and $refresh) {
        return $this->error($response, 'invalid_token', 'access token timed out', 403);
      }

      $prefix = 'Bearer ';
      if (substr($auth, 0, strlen($prefix)) == $prefix) {
          $auth = substr($auth, strlen($prefix));
      }
      if($token != $auth) {
        return $this->error($response, 'Unauthorized', 'bad access token', 404);
      }
    }

    $cip = $request->getClientIp();
    # Count the number of requests per minute
    $bucket = 'ratelimit-'.floor(time()/60).'-ip-'.$cip;

    if(Cache::get($bucket) >= getenv('LIMIT_REQUESTS_PER_MINUTE')) {
      return $this->error($response, 'slow_down');
    }

    # Mark for rate limiting
    Cache::incr($bucket);
    Cache::expire($bucket, 60);
    #####################

    $cg = Cache::get('client_credentials');
    if (!$cg) {
      $cg = self::refresh_client_credentials();
      if (!$cg) {
        return $this->error($response, 'Unauthorized', 'cannot get client credentials', 404);
      }
    }
    list($errno, $html_code, $data) = self::get_data($path, $cg, $request->query);
    if ($html_code == 403) {
      $cg = self::refresh_client_credentials();
      if (!$cg) {
        return $this->error($response, 'Unauthorized', 'cannot get client credentials', 404);
      }
      list($errno, $html_code, $data) = self::get_data($path, $cg, $request->query);
    }

    if (array_key_exists('content-type', self::$headers)) {
      $response->headers->set('content-type', self::$headers['content-type']);
    }

    if ($errno == 0) {
      $response->setContent($data);
    }
    else {
      return $this->error($response, 'invalid_request', 'cURL error ' . strval($errno));
    }

    return $response;
  }

  private function _json($data) {
    return json_encode($data, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)."\n";
  }

}
