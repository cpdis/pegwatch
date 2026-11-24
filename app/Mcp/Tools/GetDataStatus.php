<?php

namespace App\Mcp\Tools;

use App\Agent\Lookup;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_data_status')]
#[Description('How fresh Pegwatch\'s copy of the register is: when it was last checked, the register extract that check used, whether it is stale, and totals. Call this before saying anything is current.')]
#[IsReadOnly]
#[IsIdempotent]
class GetDataStatus extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->result(Lookup::status());
    }
}
