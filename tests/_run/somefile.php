<?php

use Symfony\Component\HttpFoundation\Request;

class App
{
  public function index(Request $request): void
  {
    json_decode($request->getContent(true));
  }
}