<?php

namespace PhpRedisQueue\dashboard\models;

class Queue
{
  public $name;
  public $jobs = [];

  public function __construct(array $data)
  {
    foreach ($data as $k => $v) {
      $this->$k = $v;
    }

    $this->jobs = array_map(fn ($job) => new Job($job), $this->jobs);
  }

  // public function get()
  // {
  //   return $this->data;
  // }
}
