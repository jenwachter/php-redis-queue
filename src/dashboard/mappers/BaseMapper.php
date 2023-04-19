<?php

namespace PhpRedisQueue\dashboard\mappers;

class BaseMapper
{
  public function __construct(protected \Predis\Client $redis)
  {

  }
}
