<?php

namespace Jam\Models;

interface IRelationList
{
    public function getRelationList($deep = false);
    public function setRelationKeys();
}
