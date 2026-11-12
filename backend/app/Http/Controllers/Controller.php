<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /** Échappe les jokers d'une recherche LIKE. */
    protected function like(string $terme): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($terme)).'%';
    }
}
