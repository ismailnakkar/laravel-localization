<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Localization\Http\SwitchLocale;

// `web`: session and CSRF. POST: it changes state and crawlers never submit it.
Route::post('locale', SwitchLocale::class)->middleware('web')->name('localization.switch');
