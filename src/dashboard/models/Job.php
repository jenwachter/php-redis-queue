<?php

namespace PhpRedisQueue\dashboard\models;

class Job
{
  public function __construct(string $data)
  {
    $this->data = json_decode($data);
  }

  // public function get()
  // {
  //   return $this->data;
  // }
}
