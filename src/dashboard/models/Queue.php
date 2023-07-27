<?php

namespace PhpRedisQueue\dashboard\models;

class Queue extends Base
{
  public $name;
  public $jobs = [];
}
