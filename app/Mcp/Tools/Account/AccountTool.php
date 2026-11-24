<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Watches;
use App\Mcp\Tools\AgentTool;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/** A tool on /mcp/account: acts for the person who signed the agent in. */
abstract class AccountTool extends AgentTool
{
    protected function user(Request $request): User
    {
        $user = $request->user('api');
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function watch(Request $request, mixed $id): ?WatchArea
    {
        return Watches::find($this->user($request), $id);
    }

    protected function idSchema(JsonSchema $schema): mixed
    {
        return $schema->integer()->description('The watch id, from list_my_watches.')->required();
    }
}
