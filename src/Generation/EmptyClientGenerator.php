<?php
/*
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
declare(strict_types=1);

namespace Google\Generator\Generation;

use Google\Generator\Ast\AST;
use Google\Generator\Ast\PhpClass;
use Google\Generator\Ast\PhpFile;

class EmptyClientGenerator
{
    public static function Generate(SourceFileContext $ctx, ServiceDetails $serviceDetails): PhpFile
    {
        return (new EmptyClientGenerator($ctx, $serviceDetails))->GenerateImpl();
    }

    private SourceFileContext $ctx;
    private ServiceDetails $serviceDetails;

    private function __construct(SourceFileContext $ctx, ServiceDetails $serviceDetails)
    {
        $this->ctx = $ctx;
        $this->serviceDetails = $serviceDetails;
    }

    private function GenerateImpl(): PhpFile
    {
        // Generate file content
        $file = AST::file($this->GenerateClass());
        // Finalize as required by the source-context; e.g. add top-level 'use' statements.
        return $this->ctx->finalize($file);
    }

    private function GenerateClass(): PhpClass
    {
        return AST::class($this->serviceDetails->emptyClientType);
        // TODO: Class content
    }
}
