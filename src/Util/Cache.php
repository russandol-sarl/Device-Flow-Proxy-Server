<?php

namespace App\Util;

class Cache {
  private $params;
  private $mongo;
  private $col;
  
  function __construct($dbname, $user, $password, $address, $port) {
    $this->connect($dbname, $user, $password, $address, $port);
  }

  public function connect($dbname, $user, $password, $address, $port) {
    $host = 'mongodb://' . rawurlencode($user) . ':' . rawurlencode($password) . '@' . $address . ':' . $port;
    $this->mongo = new \MongoDB\Client($host.'/'.$dbname);
    $this->col = $this->mongo->selectCollection($dbname, "cache");
    $this->col->createIndex([ "expireAt" => 1 ], [ "expireAfterSeconds" => 0 ]);
  }
  
  public function dump() {
    $obj = $this->col->findOne();
    $cursor = $this->col->find();
    foreach ($cursor as $doc) {
      var_dump($doc);
    }
  }

  public function convertToExpireAt($exp) {
    return new \MongoDB\BSON\UTCDateTime(round(microtime(true) * 1000) + ($exp * 1000));
  }
  
  public function set($key, $value, $exp=600) {
    $this->col->updateMany(
      [ 'key' => $key ],
      [ '$set' => 
        [ 'expireAt' => $this->convertToExpireAt($exp),
          'value' => $value
        ]
      ],
      [ 'upsert' => true ]
    );
  }

  public function get($key) {
    $doc = $this->col->findOne([ "key" => $key ]);
    if ($doc) {
      return $doc->value;
    }
    else {
      return null;
    }
  }

  public function add($key, $value, $exp=600) {
    this->set($key, $value, $exp);
  }

  public function expire($key, $exp) {
    $this->col->updateMany(
      [ 'key' => $key ],
      [ '$set' =>
        [ 'expireAt' => $this->convertToExpireAt($exp) ]
      ]
    );
  }

  public function incr($key, $value=1) {
    $this->col->updateMany(
      [ 'key' => $key ],
      [ '$inc' =>
        [ 'value' => $value ]
      ]
    );
  }

  public function delete($key) {
    $this->col->deleteMany([ 'key' => $key ]);
  }
}
