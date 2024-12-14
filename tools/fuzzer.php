<?php declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Settings\Logger as SettingsLogger;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use Revolt\EventLoop;
use Webmozart\Assert\Assert;

use function Amp\async;
use function Amp\File\write;

/*
Copyright 2016-2020 Daniil Gentili
(https://daniil.it)
This file is part of MadelineProto.
MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details.
You should have received a copy of the GNU General Public License along with MadelineProto.
If not, see <http://www.gnu.org/licenses/>.
 */

require 'vendor/autoload.php';
$logger = new Logger(new SettingsLogger);

set_error_handler(['\danog\MadelineProto\Exception', 'ExceptionErrorHandler']);

/**
 * Get TL info of layer.
 *
 * @internal
 *
 * @return void
 */
function getTL()
{
    $layerFile = glob(__DIR__."/../src/TL_telegram_v*.tl")[0];
    $layer = new TL();
    $layer->init((new TLSchema)->setAPISchema($layerFile)->setSecretSchema(''));

    return ['methods' => $layer->getMethods(), 'constructors' => $layer->getConstructors()];
}
$layer = getTL();
$res = '';

$bot = new \danog\MadelineProto\API('bot.madeline');
$bot->start();
$bot->updateSettings((new TLSchema)->setFuzzMode(true));
Assert::true($bot->isSelfBot(), "bot.madeline is not a bot!");

$user = new \danog\MadelineProto\API('secret.madeline');
$user->start();
$user->updateSettings((new TLSchema)->setFuzzMode(true));
Assert::true($user->isSelfUser(), "secret.madeline is not a user!");

$methods = [];
foreach ($layer['methods']->by_id as $constructor) {
    $name = $constructor['method'];
    if (strtolower($name) === 'account.deleteaccount'
        || strtolower($name) === 'auth.logout'
        || $name === 'auth.resetAuthorizations'
        || $name === 'auth.dropTempAuthKeys'
        || $name === 'account.resetAuthorization'
        || $name === 'account.resetPassword'
        || $name === 'account.updateUsername'
        || $name === 'photos.updateProfilePhoto'
        || $name === 'photos.uploadProfilePhoto'
        || !str_contains($name, '.')) {
        continue;
    }
    [$namespace, $method] = explode('.', $name);

    $methods["bot $name"]= async(static function () use ($namespace, $method, $bot, $name, &$methods): void {
        try {
            $bot->{$namespace}->{$method}();
        } catch (RPCErrorException) {
        }
        unset($methods["bot $name"]);
    });
    $methods["user $name"] = async(static function () use ($namespace, $method, $user, $name, &$methods): void {
        try {
            $user->{$namespace}->{$method}();
        } catch (RPCErrorException) {
        }
        unset($methods["user $name"]);
    });
}

EventLoop::unreference(EventLoop::repeat(1.0, function () use (&$methods) {
    echo "Processing ".implode(", ", array_keys($methods)).PHP_EOL;
}));

var_dump(array_map('strval', \Amp\Future\awaitAll($methods)[0]));
