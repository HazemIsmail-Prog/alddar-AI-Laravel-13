<?php

namespace App\Http\Controllers;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

abstract class Controller
{
    protected function domain(callable $callback): Response
    {
        try {
            $result = $callback();

            if ($result instanceof Response) {
                return $result;
            }

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
