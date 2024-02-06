<?php

namespace App\Util;

class Helpers {  
  /*
  // Check if environment variables are defined, or return an error
  $required = ['BASE_URL', 'LIMIT_REQUESTS_PER_MINUTE', 'AUTHORIZATION_ENDPOINT', 'TOKEN_ENDPOINT'];
  $complete = true;
  foreach($required as $r) {
    if(!getenv($r))
      $complete = false;
  }
  if(!$complete) {
    echo "Missing app configuration.\n";
    echo "Please copy .env.example to .env and fill out the variables, or\n";
    echo "define all environment variables accordingly.\n";
    die(1);
  }

  if(getenv('MONGODB_DB')) {
    $result = Cache::connect();
  }
  */

  public static function base64_urlencode($string) {
    return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
  }

  public static function random_alpha_string($len) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $str = '';
    for($i=0; $i<$len; $i++)
      $str .= substr($chars, random_int(0, strlen($chars)-1), 1);
    return $str;
  }
}
