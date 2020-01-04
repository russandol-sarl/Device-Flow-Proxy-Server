<?php
use Dotenv\Dotenv;

// Load .env file if exists
$dotenv = Dotenv::create(__DIR__.'/..');
if(file_exists(__DIR__.'/../.env')) {
  $dotenv->load();
}

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

function view($template, $data=[]) {
  global $templates;
  return $templates->render($template, $data);
}

function base64_urlencode($string) {
  return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
}

function random_alpha_string($len) {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  $str = '';
  for($i=0; $i<$len; $i++)
    $str .= substr($chars, random_int(0, strlen($chars)-1), 1);
  return $str;
}

class Cache {
  private static $mongo;
  private static $col;

  public static function connect($host=false,$dbname=false) {
    if(!isset(self::$mongo)) {
      if (!$dbname) {
        $dbname = getenv('MONGODB_DB');
      }
      if (!$host) {
        $host = 'mongodb://' . rawurlencode(getenv('MONGODB_USER')) . ':' . rawurlencode(getenv('MONGODB_PASSWORD')) . '@' . getenv('MONGODB_ADDRESS') . ':' . getenv('MONGODB_PORT');
      }
      self::$mongo = new MongoDB\Client($host.'/'.$dbname);
      self::$col = self::$mongo->selectCollection($dbname, "cache");
      self::$col->createIndex([ "expireAt" => 1 ], [ "expireAfterSeconds" => 0 ]);
    }
  }
  
  public static function dump() {
    $obj = self::$col->findOne();
    $cursor = self::$col->find();
    foreach ($cursor as $doc) {
      var_dump($doc);
    }
  }

  public static function convertToExpireAt($exp) {
    return new \MongoDB\BSON\UTCDateTime(round(microtime(true) * 1000) + ($exp * 1000));
  }
  
  public static function set($key, $value, $exp=600) {
    self::connect();
    self::$col->updateMany(
      [ 'key' => $key ],
      [ '$set' => 
        [ 'expireAt' => self::convertToExpireAt($exp),
          'value' => $value
        ]
      ],
      [ 'upsert' => true ]
    );
  }

  public static function get($key) {
    self::connect();
    $doc = self::$col->findOne([ "key" => $key ]);
    if ($doc) {
      return $doc->value;
    }
    else {
      return null;
    }
  }

  public static function add($key, $value, $exp=600) {
    self::connect();
    self::set($key, $value, $exp);
  }

  public static function expire($key, $exp) {
    self::connect();
    self::$col->updateMany(
      [ 'key' => $key ],
      [ '$set' =>
        [ 'expireAt' => self::convertToExpireAt($exp) ]
      ]
    );
  }

  public static function incr($key, $value=1) {
    self::connect();

    self::$col->updateMany(
      [ 'key' => $key ],
      [ '$inc' =>
        [ 'value' => $value ]
      ]
    );
  }

  public static function delete($key) {
    self::connect();
    self::$col->deleteMany([ 'key' => $key ]);
  }
}
