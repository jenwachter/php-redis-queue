<?php

namespace PhpRedisQueue\dashboard\models;

class Base
{
  public function __construct($data)
  {
    foreach ($data as $k => $v) {
      $this->$k = $v;
    }
  }
}
