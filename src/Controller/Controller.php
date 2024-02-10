<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Util\Helpers;
use App\Util\Cache;

class Controller extends AbstractController {
  const MSG_VER_ERROR = 'version_mismatch';
  const MSG_VER_ERROR_LONG = 'Votre version du plugin est trop ancienne, veuillez la mettre à jour';
  const ACCESS_EXPIRE = 12600;

  #TODO varier erreur 400 (403 pour token invalide ? Cf. plugin.py)
  private function error($error, $error_description=false, $errno = Response::HTTP_BAD_REQUEST) {
    $data = [
      'error' => $error
    ];
    if($error_description) {
      $data['error_description'] = $error_description;
    }

    $response = new JsonResponse($data);
    $response->setStatusCode($errno);
    return $response;
  }

  private function html_error($error, $error_description, $errno = Response::HTTP_BAD_REQUEST) {
    return $this->render('error.html.twig', [
      'error' => $error,
      'error_description' => $error_description
    ]);
  }

  private function success($data) {
    $response = new JsonResponse($data);
    return $response;
  }

  # Home Page
  #[Route('/', name: 'index', methods: ['GET'])]
  public function index(): Response {
    return $this->render('index.html.twig');
  }

  # Check version of env file if defined against user agent header
  private function checkVersion(Request $request) {
    $versionMin = $this->getParameter('app_version_min');
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

  private function connectCache() {
    return new Cache($this->getParameter('app_mongodb_db'), $this->getParameter('app_mongodb_user'), $this->getParameter('app_mongodb_password'), $this->getParameter('app_mongodb_address'), $this->getParameter('app_mongodb_port'));
  }

  # A device submits a request here (POST) to generate a new device and user code
  #[Route('/device/code', name: 'generate_code', methods: ['POST'])]
  public function generate_code(Request $request): Response {
    # Params:
    # client_id
    # scope

    if (!$this->checkVersion($request)) {
      return $this->error(self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }

    # client_id is required
    $client_id = $request->request->get('client_id');
    if($client_id == null) {
      return $this->error('invalid_request', 'Missing client_id');
    }

    # We've validated everything we can at this stage.
    # Generate a verification code and cache it along with the other values in the request.
    $device_code = bin2hex(random_bytes(32));
    # Generate a PKCE code_verifier and store it in the cache too
    $pkce_verifier = bin2hex(random_bytes(32));
    $cache_content = [
      'client_id' => $client_id,
      'client_secret' => $request->request->get('client_secret'),
      'scope' => $request->request->get('scope'),
      'device_code' => $device_code,
      'pkce_verifier' => $pkce_verifier,
    ];
    $user_code = Helpers::random_alpha_string(4).'-'.Helpers::random_alpha_string(4);

    $cache = $this->connectCache();
    $cache->set(str_replace('-', '', $user_code), $cache_content, 300); # store without the hyphen

    # Add a placeholder entry with the device code so that the token route knows the request is pending
    $cache->set($device_code, [
      'timestamp' => time(),
      'status' => 'pending'
    ], 300);

    $data = [
      'device_code' => $device_code,
      'user_code' => $user_code,
      'verification_uri' => $this->getParameter('app_base_url').'/device',
      'expires_in' => 300,
      'interval' => round(60/$this->getParameter('app_limit_requests_per_minute'))
    ];

    return $this->success($data);
  }

  # The user visits this page in a web browser (GET)
  # This interface provides a prompt to enter a device code, which then begins the actual OAuth flow
  #[Route('/device', name: 'device', methods: ['GET'])]
  public function device(Request $request): Response {
    return $this->render('device.html.twig', [
      'code' => $request->query->get('code'),
      'state' => $request->query->get('state')
    ]);
  }

  # The browser submits a form that is a GET request to this route, which verifies
  # and looks up the user code, and then redirects to the real authorization server
  #[Route('/auth/verify_code', name: 'verify_code', methods: ['GET'])]
  public function verify_code(Request $request): Response {
    $user_code = $request->query->get('code');
    if($user_code == null) {
      return $this->html_error('invalid_request', 'Aucun code n\'a été entré');
    }

    # Remove hyphens and convert to uppercase to make it easier for users to enter the code
    $user_code = strtoupper(str_replace('-', '', $user_code));

    $cache = $this->connectCache();
    $cache_content = $cache->get($user_code);
    if(!$cache_content) {
      return $this->html_error('invalid_request', 'Code non valide');
    }

    $state = bin2hex(random_bytes(16));

    $get_state = $request->query->get('state');
    if(!empty($get_state)) {
      $state .= $get_state;
    }

    $cache->set('state:'.$state, [
      'user_code' => $user_code,
      'timestamp' => time(),
    ], 300);

    // TODO: might need to make this configurable to support OAuth servers that have
    // custom parameters for the auth endpoint
    $query = [
      'response_type' => 'code',
      'client_id' => $cache_content->client_id,
      'state' => $state,
      'duration' => $this->getParameter('app_duration'),
    ];
    if($cache_content->scope) {
      $query['scope'] = $cache_content->scope;
    }
    if($this->getParameter('app_pkce')) {
      $pkce_challenge = Helpers::base64_urlencode(hash('sha256', $cache_content->pkce_verifier, true));
      $query['code_challenge'] = $pkce_challenge;
      $query['code_challenge_method'] = 'S256';
    }

    $authURL = $this->getParameter('app_authorization_endpoint') . '?' . http_build_query($query);

    $response = new Response();
    $response->setStatusCode(Response::HTTP_FOUND);
    $response->headers->set('Location', $authURL);
    return $response;
  }

  # After the user logs in and authorizes the app on the real auth server, they will
  # be redirected back to here (GET). We'll need to exchange the auth code for an access token,
  # and then show a message that instructs the user to go back to their TV and wait.
  #[Route('/auth/redirect', name: 'myredirect', methods: ['GET'])]
  public function myredirect(Request $request): Response {
    # Check if error
    $error = $request->query->get('error');
    if($error) {
      return $this->html_error($error, $request->query->get('error_description'));
    }

    $get_state = $request->query->get('state');
    # Verify input params
    if($get_state == false || $request->query->get('code') == false) {
      return $this->html_error('Invalid Request', 'Des paramètres manquent dans la requête');
    }

    # Check that the state parameter matches
    $cache = $this->connectCache();
    if(!($state=$cache->get('state:'.$get_state))) {
      return $this->html_error('Invalid State', 'Le paramètre state n\'est pas valide');
    }

    # Look up the info from the user code provided in the state parameter
    $cache_content = $cache->get($state->user_code);
    if($cache_content == false) {
      return $this->html_error('Invalid Request', 'user_code introuvable');
    }

    $flow = $this->getParameter('app_flow');
    if (!$flow || (strtoupper($flow) != 'DEVICE')) {
      $usage_points_id = $request->query->get('usage_point_id');
      if($usage_points_id == false) {
        return $this->html_error('Invalid Request', 'Le paramètre usage_point_id manque dans la requête');
      }
      $access_token = new \stdClass();
      do {
        $access_token->access_token = bin2hex(random_bytes(32));
      } while($cache->get('access_token:'.$access_token->access_token));
      $cache->set('access_token:'.$access_token->access_token, $usage_points_id, self::ACCESS_EXPIRE);
      do {
        $access_token->refresh_token = bin2hex(random_bytes(32));
      } while($cache->get('refresh_token:'.$access_token->refresh_token));
      $cache->set('refresh_token:'.$access_token->refresh_token, $usage_points_id, 4*365*24*60*60);
      $access_token->token_type = 'Bearer';
      $access_token->expires_in = self::ACCESS_EXPIRE;
      $access_token->usage_points_id = $usage_points_id;
      $access_token->scope = '';
      $cache->set($cache_content->device_code, [
        'status' => 'complete',
        'token_response' => $access_token
      ], 120);
      $cache->delete($state->user_code);
    }
    else {
      # Exchange the authorization code for an access token

      # TODO: Might need to provide a way to customize this request in case of
      # non-standard OAuth 2 services

      $params = [
        'grant_type' => 'authorization_code',
        'code' => $request->query->get('code'),
        'client_id' => $cache_content->client_id,
      ];
      $redirect_uri = $this->getParameter('app_redirect_uri');
      if ($redirect_uri) {
        $params['redirect_uri'] = $redirect_uri;
      }
      $client_secret = $this->getParameter('app_client_secret');
      if($client_secret) {
        $params['client_secret'] = $client_secret;
      }
      elseif($cache_content->client_secret) {
        $params['client_secret'] = $cache_content->client_secret;
      }
      if($this->getParameter('app_pkce')) {
        $params['code_verifier'] = $cache_content->pkce_verifier;
      }

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $this->getParameter('app_token_endpoint'));
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $token_response = curl_exec($ch);
      $access_token = json_decode($token_response);

      if(!$access_token || !property_exists($access_token, 'access_token')) {
        # If there are any problems getting an access token, kill the request and display an error
        $cache->delete($state->user_code);
        $cache->delete($cache_content->device_code);
        return $this->html_error('Error Logging In', 'Il y a eu une erreur en essayant d\'obtenir un jeton d\'accès du service <p><pre>'.$token_response.'</pre></p>');
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
      $cache->set($cache_content->device_code, [
        'status' => 'complete',
        'token_response' => $access_token
      ], 120);
      $cache->delete($state->user_code);
    }
    return $this->render('signed-in.html.twig');
  }

  private static $headers;

  private static function resetHeaders() {
    self::$headers = [];
  }

  private static function setHeader($curl, $header) {
    $len = strlen($header);
    $header = explode(':', $header, 2);
    if (count($header) < 2) // ignore invalid headers
      return $len;

    self::$headers[strtolower(trim($header[0]))][] = trim($header[1]);

    return $len;
  }

  # Proxy to TOKEN_ENDPOINT
  #[Route('/device/proxy', name: 'proxy', methods: ['POST'])]
  public function proxy(Request $request): Response {
    if (!$this->checkVersion($request)) {
      return $this->error(self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }

    $params = $request->request->all();

    $client_secret = $this->getParameter('app_client_secret');
    if($client_secret) {
      $params['client_secret'] = $client_secret;
    }

    $redirect_uri = $this->getParameter('app_redirect_uri');
    if ($redirect_uri) {
        $query = [
          'redirect_uri' => $redirect_uri,
        ];
        $tokenURL = $this->getParameter('app_token_endpoint') . '?' . http_build_query($query);
    }
    else {
        $tokenURL = $this->getParameter('app_token_endpoint');
    }

    self::resetHeaders();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenURL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // this function is called by curl for each header received
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, '\App\Controller\Controller::setHeader');

    $token_response = curl_exec($ch);

    $response = new Response();
    if (array_key_exists('content-type', self::$headers)) {
      $response->headers->set('content-type', self::$headers['content-type']);
    }

    $response->setContent($token_response);
    return $response;
  }

  # Meanwhile, the device is continually posting (POST) to this route waiting for the user to
  # approve the request. Once the user approves the request, this route returns the access token.
  # In addition to the standard OAuth error responses defined in https://tools.ietf.org/html/rfc6749#section-4.2.2.1
  # the server should return: authorization_pending and slow_down
  #[Route('/device/token', name: 'access_token', methods: ['POST'])]
  public function access_token(Request $request): Response {
    if (!$this->checkVersion($request)) {
      return $this->error(self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }

    $client_id = $request->request->get('client_id');
    if($client_id == null || $request->request->get('grant_type') == null) {
      return $this->error('invalid_request', 'Missing client_id or grant_type');
    }

    $cache = $this->connectCache();

    # This server supports the device_code response type
    if($request->request->get('grant_type') == 'urn:ietf:params:oauth:grant-type:device_code') {
      if($request->request->get('device_code') == null) {
        return $this->error('invalid_request', 'Missing device_code');
      }
      $device_code = $request->request->get('device_code');

      #####################
      ## RATE LIMITING

      # Count the number of requests per minute
      $bucket = 'ratelimit-'.floor(time()/60).'-'.$device_code;

      if($cache->get($bucket) >= $this->getParameter('app_limit_requests_per_minute')) {
        return $this->error('slow_down');
      }

      # Mark for rate limiting
      $cache->incr($bucket);
      $cache->expire($bucket, 60);
      #####################

      # Check if the device code is in the cache
      $data = $cache->get($device_code);

      if(!$data) {
        return $this->error('invalid_grant', 'device_code not found in db');
      }

      if($data && $data->status == 'pending') {
        return $this->error('authorization_pending');
      } else if($data && $data->status == 'complete') {
        # return the raw access token response from the real authorization server
        $cache->delete($device_code);
        return $this->success($data->token_response);
      } else {
        return $this->error('invalid_grant', 'Authorization unsuccessful');
      }
    }
    // To test:
    // curl -X POST url/device/token -H 'Content-Type: application/x-www-form-urlencoded' -d 'grant_type=refresh_token&client_id=xxxx'
    elseif($request->request->get('grant_type') == 'refresh_token') {
      if($client_id != $this->getParameter('app_client_id')) {
        return $this->error('invalid_request', 'Bad client_id');
      }
      $usage_points_id = $request->request->get('usage_points_id');
      if($usage_points_id == null) {
        return $this->error('invalid_request', 'Missing usage_points_id');
      }
      $refresh_token = $request->request->get('refresh_token');
      if($refresh_token == null) {
        return $this->error('invalid_request', 'Missing refresh_token');
      }
      #####################
      ## RATE LIMITING

      $cip = $request->getClientIp();
      # Count the number of requests per minute
      $bucket = 'ratelimit-'.floor(time()/60).'-ip-'.$cip;

      if($cache->get($bucket) >= $this->getParameter('app_limit_requests_per_minute')) {
        return $this->error('slow_down');
      }

      # Mark for rate limiting
      $cache->incr($bucket);
      $cache->expire($bucket, 60);
      #####################

      $old_usage_points_id = $cache->get('refresh_token:'.$refresh_token);
      if (!$old_usage_points_id) {
        return $this->error('invalid_request', 'refresh_token not found in database');
      }
      if ($old_usage_points_id != $usage_points_id) {
        return $this->error('invalid_request', 'refresh_token not corresponding to usage_points_id');
      }

      $access_token = new \stdClass();
      do {
        $access_token->access_token = bin2hex(random_bytes(32));
      } while($cache->get('access_token:'.$access_token->access_token));
      $cache->set('access_token:'.$access_token->access_token, $usage_points_id, self::ACCESS_EXPIRE);
      $access_token->refresh_token = $refresh_token;
      $access_token->token_type = 'Bearer';
      $access_token->expires_in = self::ACCESS_EXPIRE;
      $access_token->scope = '';
      $response = new JsonResponse($access_token);
      return $response;
    }
    else {
      return $this->error('unsupported_grant_type', 'Only \'urn:ietf:params:oauth:grant-type:device_code\' and refresh_token are supported.');
    }
  }

  # renew client_credentials
  private function refresh_client_credentials(){
    $envId = $this->getParameter('app_client_id');
    $envSecret = $this->getParameter('app_client_secret') ;
    $params = [
      'grant_type' => 'client_credentials',
      'client_id' => $envId,
      'client_secret' => $envSecret,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->getParameter('app_token_endpoint_v3'));
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
      $cache = $this->connectCache();
      $cache->set('client_credentials', $access_token, $expires_in);
    }
    return $access_token;
  }

  # get json data with cURL
  private function get_data($path, $cg, $query){
    $query2 = http_build_query($query->all());

    self::resetHeaders();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->getParameter('app_data_endpoint') . '/' . $path. '?' . $query2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, '\App\Controller\Controller::setHeader');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: ' . $cg->token_type . ' '. $cg->access_token, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'));
    $data = curl_exec($ch);
    $html_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    return array($errno, $html_code, $data);
  }

  # Proxy to data (GET)
  #[Route('/data/proxy/{path}', name: 'proxy_data', methods: ['GET'], requirements: ['path' => '.+'])]
  public function proxy_data(Request $request, string $path): Response {
    if (!$this->checkVersion($request)) {
      return $this->error(self::MSG_VER_ERROR, self::MSG_VER_ERROR_LONG);
    }

    if($path == null) {
      return $this->error('invalid_request', 'path empty');
    }

    $usage_point_id = $request->query->get('usage_point_id');
    if($usage_point_id == null) {
      return $this->error('invalid_request', 'Missing usage_point_id');
    }

    $cache = $this->connectCache();
    if(!$this->getParameter('app_disable_data_enpoint_auth')) {
      $auth = $request->headers->get('Authorization');
      if(!$auth) {
        return $this->error('Unauthorized', 'Autorization missing', Response::HTTP_NOT_FOUND);
      }
      $prefix = 'Bearer ';
      if (substr($auth, 0, strlen($prefix)) == $prefix) {
          $auth = substr($auth, strlen($prefix));
      }
      $usage_points_id_in_db = $cache->get('access_token:'.$auth);
      if(!$usage_points_id_in_db) {
        return $this->error('Unauthorized', 'Access token not found', Response::HTTP_FORBIDDEN);
      }
      $usage_points_id_tab = explode(',', $usage_points_id_in_db);
      if(!in_array($usage_point_id, $usage_points_id_tab)) {
        return $this->error('Unauthorized', 'Bad access token', Response::HTTP_NOT_FOUND);
      }
    }

    $cip = $request->getClientIp();
    # Count the number of requests per minute
    $bucket = 'ratelimit-'.floor(time()/60).'-ip-'.$cip;

    if($cache->get($bucket) >= $this->getParameter('app_limit_requests_per_minute')) {
      return $this->error('slow_down');
    }

    # Mark for rate limiting
    $cache->incr($bucket);
    $cache->expire($bucket, 60);
    #####################

    $cg = $cache->get('client_credentials');
    if (!$cg) {
      $cg = self::refresh_client_credentials();
      if (!$cg) {
        return $this->error('Unauthorized', 'Cannot get client credentials', Response::HTTP_NOT_FOUND);
      }
    }
    list($errno, $html_code, $data) = self::get_data($path, $cg, $request->query);
    if ($html_code == 403) {
      $cg = self::refresh_client_credentials();
      if (!$cg) {
        return $this->error('Unauthorized', 'Cannot get client credentials', Response::HTTP_NOT_FOUND);
      }
      list($errno, $html_code, $data) = self::get_data($path, $cg, $request->query);
    }

    if ($errno != 0) {
      return $this->error('invalid_request', 'cURL error ' . strval($errno));
    }
    else {
      $response = new Response();
      if (array_key_exists('content-type', self::$headers)) {
        $response->headers->set('content-type', self::$headers['content-type']);
      }
      $response->setContent($data);
      return $response;
    }
  }

}
?>
