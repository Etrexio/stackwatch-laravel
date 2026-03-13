<?php

namespace StackWatch\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static ?string captureException(\Throwable $exception, array $context = [])
 * @method static ?string captureMessage(string $message, string $level = 'info', array $context = [])
 * @method static ?string capturePerformance(array $data)
 * @method static \StackWatch\Laravel\StackWatch addBreadcrumb(string $category, string $message, array $data = [], string $level = 'info')
 * @method static \StackWatch\Laravel\StackWatch setUser(?array $user)
 * @method static \StackWatch\Laravel\StackWatch setUserFromAuth()
 * @method static \StackWatch\Laravel\StackWatch setTag(string $key, string $value)
 * @method static \StackWatch\Laravel\StackWatch setTags(array $tags)
 * @method static \StackWatch\Laravel\StackWatch setExtra(string $key, mixed $value)
 * @method static \StackWatch\Laravel\StackWatch setContext(string $key, array $value)
 * @method static \StackWatch\Laravel\StackWatch clearBreadcrumbs()
 * @method static array getBreadcrumbs()
 *
 * @see \StackWatch\Laravel\StackWatch
 */
class StackWatch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \StackWatch\Laravel\StackWatch::class;
    }
}
