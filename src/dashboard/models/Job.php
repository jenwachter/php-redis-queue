<?php

namespace PhpRedisQueue\dashboard\models;

class Job extends Base
{
  public $meta;
  public $runs;
  public $job;

  public function __construct(string $data)
  {
    $data = json_decode($data);

    parent::__construct($data);
  }
}
