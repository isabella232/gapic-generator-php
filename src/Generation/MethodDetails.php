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

use Google\ApiCore\OperationResponse;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MethodDescriptorProto;
use Google\Generator\Ast\AST;
use Google\Generator\Ast\PhpMethod;
use Google\Generator\Collections\Vector;
use Google\Generator\Utils\CustomOptions;
use Google\Generator\Utils\Helpers;
use Google\Generator\Utils\ProtoHelpers;
use Google\Generator\Utils\Type;
use Google\LongRunning\OperationInfo;

abstract class MethodDetails
{
    public const NORMAL = 'normal';
    public const LRO = 'lro';
    public const PAGINATED = 'paginated';

    public static function create(ServiceDetails $svc, MethodDescriptorProto $desc): MethodDetails
    {
        // TODO: Handle further method types; e.g. streaming, paginated, ...
        return
            static::maybeCreatePaginated($svc, $desc) ?? (
                $desc->getOutputType() === '.google.longrunning.Operation' ? static::createLro($svc, $desc) :
                static::createNormal($svc, $desc)
            );
    }

    private static function maybeCreatePaginated(ServiceDetails $svc, MethodDescriptorProto $desc): ?MethodDetails
    {
        $catalog = $svc->catalog;
        $inputMsg = $catalog->msgsByFullname[$desc->getInputType()];
        $outputMsg = $catalog->msgsByFullname[$desc->getOutputType()];
        $pageSize = $inputMsg->desc->getFieldByName('page_size');
        $pageToken = $inputMsg->desc->getFieldByName('page_token');
        $nextPageToken = $outputMsg->desc->getFieldByName('next_page_token');
        $resources = $outputMsg->desc->getFieldByNumber(1);
        if (is_null($pageSize) || is_null($pageToken) || is_null($nextPageToken)) {
            return null;
        } else {
            if ($pageSize->isRepeated() || $pageSize->getType() !== GPBType::INT32) {
                throw new \Exception("page_size field must be of type int32.");
            }
            if ($pageToken->isRepeated() || $pageToken->getType() !== GPBType::STRING) {
                throw new \Exception("page_token field must be of type string.");
            }
            if ($nextPageToken->isRepeated() || $nextPageToken->getType() !== GPBType::STRING) {
                throw new \Exception("next_page_token field must be of type string.");
            }
            if (!$resources->isRepeated() || $resources->isMap()) {
                throw new \Exception("Item resources field must be a repeated field with field-number 1.");
            }
            return new class($svc, $desc, $pageSize, $pageToken, $nextPageToken, $resources) extends MethodDetails {
                public function __construct($svc, $desc, $pageSize, $pageToken, $nextPageToken, $resources)
                {
                    parent::__construct($svc, $desc);
                    $this->methodType = MethodDetails::PAGINATED;
                    $this->requestPageSizeGetter = AST::method($pageSize->getGetter());
                    $this->requestPageSizeSetter = AST::method($pageSize->getSetter());
                    $this->requestPageTokenGetter = AST::method($pageToken->getGetter());
                    $this->requestPageTokenSetter = AST::method($pageToken->getSetter());
                    $this->responseNextPageTokenGetter = AST::method($nextPageToken->getGetter());
                    $this->responseNextPageTokenSetter = AST::method($nextPageToken->getSetter());
                    $this->resourcesGetter = AST::method($resources->getGetter());
                    $this->resourcesSetter = AST::method($resources->getSetter());
                    $this->resourceType = Type::fromField($resources);
                    $this->resourcesFieldName = Helpers::toCamelCase($resources->getName());
                }

                /** @var PhpMethod *Readonly* The name of the page_size getter method. */
                public PhpMethod $requestPageSizeGetter;

                /** @var PhpMethod *Readonly* The name of the page_size setter method. */
                public PhpMethod $requestPageSizeSetter;

                /** @var PhpMethod *Readonly* The name of the page_token getter method. */
                public PhpMethod $requestPageTokenGetter;

                /** @var PhpMethod *Readonly* The name of the page_token setter method. */
                public PhpMethod $requestPageTokenSetter;

                /** @var PhpMethod *Readonly* The next_page_token getter method. */
                public PhpMethod $responseNextPageTokenGetter;

                /** @var PhpMethod *Readonly* The next_page_token setter method. */
                public PhpMethod $responseNextPageTokenSetter;

                /** @var PhpMethod *Readonly* The name of the resources getter method. */
                public PhpMethod $resourcesGetter;

                /** @var PhpMethod *Readonly* The name of the resources setter method. */
                public PhpMethod $resourcesSetter;

                /** @var Type *Readonly* The type of the resources. */
                public Type $resourceType;

                /** @var string *Readonly* The name of the resources field. */
                public string $resourcesFieldName;
            };
        }
    }

    private static function createLro(ServiceDetails $svc, MethodDescriptorProto $desc): MethodDetails
    {
        return new class($svc, $desc) extends MethodDetails {
            public function __construct($svc, $desc)
            {
                parent::__construct($svc, $desc);
                $this->methodType = MethodDetails::LRO;
                $catalog = $svc->catalog;
                $lroData = ProtoHelpers::getCustomOption($desc, CustomOptions::GOOGLE_LONGRUNNING_OPERATIONINFO, OperationInfo::class);
                if (is_null($lroData)) {
                    throw new \Exception('An LRO method must provide a `google.api.operation` option.');
                }
                $responseMsg = $catalog->msgsByFullname[$svc->packageFullName($lroData->getResponseType())];
                $metadataMsg = $catalog->msgsByFullname[$svc->packageFullName($lroData->getMetadataType())];
                $this->lroResponseType = Type::fromMessage($responseMsg->desc);
                $this->lroMetadataType = Type::fromMessage($metadataMsg->desc);
                $this->methodReturnType = Type::fromName(OperationResponse::class);
                $this->lroResponseFields = Vector::new($responseMsg->getField())->map(fn($x) => new FieldDetails($x));
            }

            /** @var Type *Readonly* The type of the LRO response. */
            public Type $lroResponseType;

            /** @var Type *Readonly* The type of the LRO metadata. */
            public Type $lroMetadataType;

            /** @var Vector *Readonly* Vector of FieldDetails; all fields of lroResponse type. */
            public Vector $lroResponseFields;
        };
    }

    private static function createNormal(ServiceDetails $svc, MethodDescriptorProto $desc): MethodDetails
    {
        return new class($svc, $desc) extends MethodDetails {
            public function __construct($svc, $desc)
            {
                parent::__construct($svc, $desc);
                $this->methodType = MethodDetails::NORMAL;
            }
        };
    }

    /** @var string *Readonly* The method type - e.g. normal, lro, server-streaming, ... */
    public string $methodType;

    /** @var string *Readonly* The name of the method, as named in the proto. */
    public string $name;

    /** @var string *Readonly* The name of this method, as required for PHP code. */
    public string $methodName;

    /** @var string *Readonly* The name of the test method testing the success case. */
    public string $testSuccessMethodName;

    /** @var string *Readonly* The name of the test method testing the exceptional case. */
    public string $testExceptionMethodName;

    /** @var Type *Readonly* The type of the method request message. */
    public Type $requestType;

    /** @var Type *Readonly* The type of the method response message. */
    public Type $responseType;

    /** @var Type *Readonly* The return type of the PHP method. */
    public Type $methodReturnType;

    /** @var Vector *Readonly* Vector of FieldDetails; all required request fields. */
    public Vector $requiredFields;

    /** @var Vector *Readonly* Vector of FieldDetails; all optional request fields. */
    public Vector $optionalFields;

    /** @var Vector *Readonly* Vector of strings; the documentation lines from the source proto. */
    public Vector $docLines;

    protected function __construct(ServiceDetails $svc, MethodDescriptorProto $desc)
    {
        $catalog = $svc->catalog;
        $inputMsg = $catalog->msgsByFullname[$desc->getInputType()];
        $outputMsg = $catalog->msgsByFullname[$desc->getOutputType()];
        $this->name = $desc->getName();
        $this->methodName = Helpers::toCamelCase($this->name);
        $this->testSuccessMethodName = $this->methodName . 'Test';
        $this->testExceptionMethodName = $this->methodName . 'ExceptionTest';
        $this->requestType = Type::fromMessage($inputMsg->desc);
        $this->responseType = Type::fromMessage($outputMsg->desc);
        $this->methodReturnType = $this->responseType;
        $allFields = Vector::new($inputMsg->getField())->map(fn($x) => new FieldDetails($x));
        $this->requiredFields = $allFields->filter(fn($x) => $x->isRequired);
        $this->optionalFields = $allFields->filter(fn($x) => !$x->isRequired);
        $this->docLines = $desc->leadingComments;
    }
}
