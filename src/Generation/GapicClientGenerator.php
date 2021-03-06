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

use Google\ApiCore\ApiException;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\LongRunning\OperationsClient;
use Google\ApiCore\OperationResponse;
use Google\ApiCore\RetrySettings;
use Google\ApiCore\Transport\GrpcTransport;
use Google\ApiCore\Transport\RestTransport;
use Google\ApiCore\Transport\TransportInterface;
use Google\Auth\FetchAuthTokenInterface;
use Google\Generator\Ast\AST;
use Google\Generator\Ast\Access;
use Google\Generator\Ast\PhpClass;
use Google\Generator\Ast\PhpClassMember;
use Google\Generator\Ast\PhpDoc;
use Google\Generator\Ast\PhpFile;
use Google\Generator\Collections\Vector;
use Google\Generator\Utils\Type;

class GapicClientGenerator
{
    public static function generate(SourceFileContext $ctx, ServiceDetails $serviceDetails): PhpFile
    {
        return (new GapicClientGenerator($ctx, $serviceDetails))->generateImpl();
    }

    private SourceFileContext $ctx;
    private ServiceDetails $serviceDetails;

    private function __construct(SourceFileContext $ctx, ServiceDetails $serviceDetails)
    {
        $this->ctx = $ctx;
        $this->serviceDetails = $serviceDetails;
        $this->hasLro = $serviceDetails->methods->any(fn($x) => $x->methodType === MethodDetails::LRO);
    }

    private function generateImpl(): PhpFile
    {
        // Generate file content
        $file = AST::file($this->generateClass())
            ->withApacheLicense($this->ctx->licenseYear)
            ->withGeneratedCodeWarning();
        // Finalize as required by the source-context; e.g. add top-level 'use' statements.
        return $this->ctx->finalize($file);
    }

    private function generateClass(): PhpClass
    {
        return AST::class($this->serviceDetails->gapicClientType)
            ->withPhpDoc(PhpDoc::block(
                PhpDoc::preFormattedText($this->serviceDetails->docLines->take(1)
                    ->map(fn($x) => "Service Description: {$x}")
                    ->concat($this->serviceDetails->docLines->skip(1))),
                PhpDoc::preFormattedText(Vector::new([
                    'This class provides the ability to make remote calls to the backing service through method',
                    'calls that map to API methods. Sample code to get started:'
                ])),
                count($this->serviceDetails->methods) === 0 ? null :
                    PhpDoc::example($this->rpcMethodExample($this->serviceDetails->methods[0])),
                PhpDoc::experimental(),
            ))
            ->withTrait($this->ctx->type(Type::fromName(\Google\ApiCore\GapicClientTrait::class)))
            ->withMember($this->serviceName())
            ->withMember($this->serviceAddress())
            ->withMember($this->servicePort())
            ->withMember($this->codegenName())
            ->withMember($this->serviceScopes())
            ->withMember($this->operationsClient())
            ->withMember($this->getClientDefaults())
            ->withMembers($this->lroMethods())
            ->withMember($this->construct())
            ->withMembers($this->serviceDetails->methods->map(fn($x) => $this->rpcMethod($x)));
    }

    private function serviceName(): PhpClassMember
    {
        return AST::constant('SERVICE_NAME')
            ->withPhpDocText('The name of the service.')
            ->withValue($this->serviceDetails->serviceName);
    }

    private function serviceAddress(): PhpClassMember
    {
        return AST::constant('SERVICE_ADDRESS')
            ->withPhpDocText('The default address of the service.')
            ->withValue($this->serviceDetails->defaultHost);
    }

    private function servicePort(): PhpClassMember
    {
        return AST::constant('DEFAULT_SERVICE_PORT')
            ->withPhpDocText('The default port of the service.')
            ->withValue($this->serviceDetails->defaultPort);
    }

    private function codegenName(): PhpClassMember
    {
        return AST::constant('CODEGEN_NAME')
            ->withPhpDocText('The name of the code generator, to be included in the agent header.')
            ->withValue('gapic');
    }

    private function serviceScopes(): PhpClassMember
    {
        return AST::property('serviceScopes')
            ->withAccess(Access::PUBLIC, Access::STATIC)
            ->withPhpDocText('The default scopes required by the service.')
            ->withValue(AST::array($this->serviceDetails->defaultScopes->toArray()));
    }

    private function operationsClient(): ?PhpClassMember
    {
        if ($this->hasLro) {
            return AST::property('operationsClient')
                ->withAccess(Access::PRIVATE);
        } else {
            return null;
        }
    }

    private function lroMethods(): Vector
    {
        if ($this->hasLro) {
            $getOperationsClient = AST::method('getOperationsClient')
                ->withAccess(Access::PUBLIC)
                ->withBody(AST::block(
                    AST::return(AST::access(AST::THIS, $this->operationsClient()))
                ))
                ->withPhpDoc(PhpDoc::block(
                    PhpDoc::text('Return an OperationsClient object with the same endpoint as $this.'),
                    PhpDoc::return($this->ctx->type(Type::fromName(OperationsClient::class))),
                    PhpDoc::experimental()
                ));
            $operationName = AST::var('operationName');
            $methodName = AST::var('methodName');
            $options = AST::var('options');
            $operation = AST::var('operation');
            $resumeOperation = AST::method('resumeOperation')
                ->withAccess(Access::PUBLIC)
                ->withParams(AST::param(null, $operationName), AST::param(null, $methodName, AST::NULL))
                ->withBody(AST::block(
                    AST::assign($options, AST::ternary(
                        AST::call(AST::ISSET)(AST::access(AST::THIS, AST::property('descriptors'))[$methodName]['longRunning']),
                        AST::access(AST::THIS, AST::property('descriptors'))[$methodName]['longRunning'],
                        AST::array([])
                    )),
                    AST::assign($operation, AST::new($this->ctx->type(Type::fromName(OperationResponse::class)))(
                        $operationName, AST::call(AST::THIS, $getOperationsClient)(), $options
                    )),
                    $operation->instanceCall(AST::method('reload'))(),
                    AST::return($operation)
                ))
                ->withPhpDoc(PhpDoc::block(
                    PhpDoc::text('Resume an existing long running operation that was previously started',
                        'by a long running API method. If $methodName is not provided, or does',
                        'not match a long running API method, then the operation can still be',
                        'resumed, but the OperationResponse object will not deserialize the',
                        'final response.'),
                    PhpDoc::param(AST::param($this->ctx->type(Type::string()), $operationName),
                        PhpDoc::text('The name of the long running operation')),
                    PhpDoc::param(AST::param($this->ctx->type(Type::string()), $methodName),
                        PhpDoc::text('The name of the method used to start the operation')),
                    PhpDoc::return($this->ctx->type(Type::fromName(OperationResponse::class))),
                    PhpDoc::experimental()
                ));
            return Vector::new([$getOperationsClient, $resumeOperation]);
        } else {
            return Vector::new([]);
        }
    }

    private function getClientDefaults(): PhpClassMember
    {
        return AST::method('getClientDefaults')
            ->withAccess(Access::PRIVATE, Access::STATIC)
            ->withBody(AST::block(
                AST::return(AST::array([
                    'serviceName' => AST::access(AST::SELF, $this->serviceName()),
                    'apiEndpoint' => AST::concat(AST::access(AST::SELF, $this->serviceAddress()), ':', AST::access(AST::SELF, $this->servicePort())),
                    'clientConfig' => AST::concat(AST::__DIR__, "/../resources/{$this->serviceDetails->clientConfigFilename}"),
                    'descriptorsConfigPath' => AST::concat(AST::__DIR__, "/../resources/{$this->serviceDetails->descriptorConfigFilename}"),
                    'gcpApiConfigPath' => AST::concat(AST::__DIR__, "/../resources/{$this->serviceDetails->grpcConfigFilename}"),
                    'credentialsConfig' => AST::array([
                        'scopes' => AST::access(AST::SELF, $this->serviceScopes()),
                    ]),
                    'transportConfig' => AST::array([
                        'rest' => AST::array([
                            'restClientConfigPath' => AST::concat(AST::__DIR__, "/../resources/{$this->serviceDetails->restConfigFilename}"),
                        ])
                    ])
                ]))
            ));
    }

    private function construct(): PhpClassMember
    {
        $ctx = $this->ctx;
        // TODO: Likely to move these two method definitions into a common location.
        $buildClientOptions = AST::method('buildClientOptions');
        $setClientOptions = AST::method('setClientOptions');
        $options = AST::var('options');
        $optionsParam = AST::param($this->ctx->type(Type::array()), $options, AST::array([]));
        $clientOptions = AST::var('clientOptions');
        return AST::method('__construct')
            ->withParams($optionsParam)
            ->withAccess(Access::PUBLIC)
            ->withBody(AST::block(
                Ast::assign($clientOptions, AST::call(AST::THIS, $buildClientOptions)($options)),
                Ast::call(AST::THIS, $setClientOptions)($clientOptions),
                $this->hasLro ?
                    AST::assign(AST::access(AST::THIS, $this->operationsClient()),
                        AST::call(AST::THIS, AST::method('createOperationsClient'))($clientOptions)) :
                    null
            ))
            ->withPhpDoc(PhpDoc::block(
                PhpDoc::text('Constructor.'),
                PhpDoc::param($optionsParam, PhpDoc::block(
                    PhpDoc::text('Optional. Options for configuring the service API wrapper.'),
                    PhpDoc::type(Vector::new([$ctx->type(Type::string())]), 'serviceAddress',
                        PhpDoc::text('**Deprecated**. This option will be removed in a future major release.',
                            'Please utilize the `$apiEndpoint` option instead.')),
                    PhpDoc::type(Vector::new([$ctx->type(Type::string())]), 'apiEndpoint',
                        PhpDoc::text('The address of the API remote host. May optionally include the port, formatted',
                            "as \"<uri>:<port>\". Default '{$this->serviceDetails->defaultHost}:{$this->serviceDetails->defaultPort}'.")),
                    PhpDoc::type(Vector::new([
                        $ctx->type(Type::string()),
                        $ctx->type(Type::array()),
                        $ctx->type(Type::fromName(FetchAuthTokenInterface::class)),
                        $ctx->type(Type::fromName(CredentialsWrapper::class))
                    ]), 'credentials',
                        PhpDoc::text('The credentials to be used by the client to authorize API calls. This option',
                            'accepts either a path to a credentials file, or a decoded credentials file as a PHP array.', PhpDoc::newLine(),
                            '*Advanced usage*: In addition, this option can also accept a pre-constructed',
                            $ctx->type(Type::fromName(FetchAuthTokenInterface::class)),
                            'object or',
                            $ctx->type(Type::fromName(CredentialsWrapper::class)),
                            'object. Note that when one of these objects are provided, any settings in $credentialsConfig will be ignored.')),
                    PhpDoc::type(Vector::new([$ctx->type(Type::array())]), 'credentialsConfig',
                        PhpDoc::text('Options used to configure credentials, including auth token caching, for the client.',
                            'For a full list of supporting configuration options, see',
                            AST::call($ctx->type(Type::fromName(CredentialsWrapper::class)), AST::method('build'))())),
                    PhpDoc::type(Vector::new([$ctx->type(Type::bool())]), 'disableRetries',
                        PhpDoc::text('Determines whether or not retries defined by the client configuration should be',
                            'disabled. Defaults to `false`.')),
                    PhpDoc::type(Vector::new([$ctx->type(Type::string()), $ctx->type(Type::array())]), 'clientConfig',
                        PhpDoc::text('Client method configuration, including retry settings. This option can be either a',
                            'path to a JSON file, or a PHP array containing the decoded JSON data.',
                            'By default this settings points to the default client config file, which is provided',
                            'in the resources folder.')),
                    PhpDoc::type(Vector::new([
                        $ctx->type(Type::string()),
                        $ctx->type(Type::fromName(TransportInterface::class))
                    ]), 'transport',
                        PhpDoc::text('The transport used for executing network requests. May be either the string `rest`',
                            'or `grpc`. Defaults to `grpc` if gRPC support is detected on the system.',
                            '*Advanced usage*: Additionally, it is possible to pass in an already instantiated',
                            $ctx->type(Type::fromName(TransportInterface::class)),
                            'object. Note that when this object is provided, any settings in `$transportConfig`, and any `$apiEndpoint`',
                            'setting, will be ignored.')),
                    PhpDoc::type(Vector::new([$ctx->type(Type::array())]), 'transportConfig',
                        PhpDoc::text('Configuration options that will be used to construct the transport. Options for',
                            'each supported transport type should be passed in a key for that transport. For example:',
                            PhpDoc::example(AST::block(
                                AST::assign(AST::var('transportConfig'), AST::array([
                                    'grpc' => AST::array(['...' => '...']),
                                    'rest' => AST::array(['...' => '...']),
                                ])))),
                            'See the', AST::call($ctx->type(Type::fromName(GrpcTransport::class)), AST::method('build'))(),
                            'and', AST::call($ctx->type(Type::fromName(RestTransport::class)), AST::method('build'))(),
                            'methods for the supported options.'))
                )),
            PhpDoc::throws($this->ctx->type(Type::fromName(\Google\ApiCore\ValidationException::class))),
            PhpDoc::experimental()
        ));
    }

    private function rpcMethod(MethodDetails $method): PhpClassMember
    {
        // TODO: Test field with no proto-doc to make sure the PhpDoc is generated correctly.
        $request = AST::var('request');
        $required = $method->requiredFields->map(fn($x) => AST::param(null, AST::var($x->camelName)));
        $optionalArgs = AST::param($this->ctx->type(Type::array()), AST::var('optionalArgs'), AST::array([]));
        $retrySettingsType = Type::fromName(RetrySettings::class);
        return AST::method($method->methodName)
            ->withAccess(Access::PUBLIC)
            ->withParams($required, $optionalArgs)
            ->withBody(AST::block(
                AST::assign($request, AST::new($this->ctx->type($method->requestType))()),
                Vector::zip($method->requiredFields, $required, fn($field, $param) => AST::call($request, $field->setter)($param)),
                $method->optionalFields->map(fn($x) => AST::if(AST::call(AST::ISSET)(AST::index($optionalArgs->var, $x->camelName)))
                    ->then(AST::call($request, $x->setter)(AST::index($optionalArgs->var, $x->camelName)))),
                AST::return($this->startCall($method, $optionalArgs, $request))
            ))
            ->withPhpDoc(PhpDoc::block(
                PhpDoc::preFormattedText($method->docLines),
                PhpDoc::example($this->rpcMethodExample($method), PhpDoc::text('Sample code:')),
                Vector::zip($method->requiredFields, $required,
                    fn($field, $param) => PhpDoc::param($param, PhpDoc::preFormattedText($field->docLines))),
                PhpDoc::param($optionalArgs, PhpDoc::block(
                    PhpDoc::Text('Optional.'),
                    $method->optionalFields->map(fn($x) =>
                        PhpDoc::type(Vector::new([$this->ctx->type($x->type)]), $x->camelName, PhpDoc::preFormattedText($x->docLines))
                    ),
                    PhpDoc::type(
                        Vector::new([$this->ctx->type($retrySettingsType), $this->ctx->type(Type::array())]),
                        'retrySettings', PhpDoc::Text(
                            'Retry settings to use for this call. Can be a ', $this->ctx->Type($retrySettingsType),
                            ' object, or an associative array of retry settings parameters. See the documentation on ',
                            $this->ctx->Type($retrySettingsType), ' for example usage.'))
                        )),
                PhpDoc::return($this->ctx->type($method->methodReturnType)),
                PhpDoc::throws($this->ctx->type(Type::fromName(ApiException::class)),
                    PhpDoc::text('if the remote call fails')),
                PhpDoc::experimental()
            ));
    }

    private function startCall($method, $optionalArgs, $request): AST
    {
        switch ($method->methodType) {
            case MethodDetails::NORMAL:
                return AST::call(AST::THIS, AST::method('startCall'))(
                    $method->name,
                    AST::access($this->ctx->type($method->responseType), AST::CLS),
                    $optionalArgs->var,
                    $request
                )->wait();
            case MethodDetails::LRO:
                return AST::call(AST::THIS, AST::method('startOperationsCall'))(
                    $method->name,
                    $optionalArgs->var,
                    $request,
                    AST::call(AST::THIS, AST::method('getOperationsClient'))()
                )->wait();
            case MethodDetails::PAGINATED:
                return AST::call(AST::THIS, AST::method('getPagedListResponse'))(
                    $method->name,
                    $optionalArgs->var,
                    AST::access($this->ctx->type($method->responseType), AST::CLS),
                    $request
                );
            default:
                throw new \Exception("Cannot handle method type: '{$method->methodType}'");
        }
    }

    private function rpcMethodExample(MethodDetails $method): AST
    {
        // TODO: Handle special arg types; e.g. resources.
        // Create a separate context, as this code isn't part of the generated client.
        $exampleCtx = new SourceFileContext('');
        switch ($method->methodType) {
            case MethodDetails::NORMAL:
                $code = $this->rpcMethodExampleNormal($exampleCtx, $method);
                break;
            case MethodDetails::LRO:
                $code = $this->rpcMethodExampleLro($exampleCtx, $method);
                break;
            case MethodDetails::PAGINATED:
                $code = $this->rpcMethodExamplePaginated($exampleCtx, $method);
                break;
            default:
                throw new \Exception("Cannot handle method-type: '{$method->methodType}'");
        }
        $exampleCtx->finalize(null);
        return $code;
    }

    private function rpcMethodExampleNormal(SourceFileContext $ctx, MethodDetails $method): AST
    {
        $serviceClient = AST::var($this->serviceDetails->clientVarName);
        $callVars = $method->requiredFields->map(fn($x) => AST::var($x->camelName));
        return AST::block(
            AST::assign($serviceClient, AST::new($ctx->type($this->serviceDetails->emptyClientType))()),
            AST::try(
                Vector::zip($callVars, $method->requiredFields, fn($var, $f) => AST::assign($var, $f->type->defaultValue())),
                AST::call($serviceClient, AST::method($method->methodName))($callVars)
            )->finally(
                AST::call($serviceClient, AST::method('close'))()
            )
        );
    }

    private function rpcMethodExampleLro(SourceFileContext $ctx, MethodDetails $method): AST
    {
        $serviceClient = AST::var($this->serviceDetails->clientVarName);
        $callVars = $method->requiredFields->map(fn($x) => AST::var($x->camelName));
        $operationResponse = AST::var('operationResponse');
        $result = AST::var('result');
        $error = AST::var('error');
        $operationName = AST::var('operationName');
        $newOperationResponse = AST::var('newOperationResponse');
        $useResponseFn = fn($var) => AST::if($var->operationSucceeded())
            ->then(
                AST::assign($result, $var->getResult()),
                '// doSomethingWith($result)'
            )
            ->else(
                AST::assign($error, $var->getError()),
                '// handleError($error)'
            );
        return AST::block(
            AST::assign($serviceClient, AST::new($ctx->type($this->serviceDetails->emptyClientType))()),
            AST::try(
                Vector::zip($callVars, $method->requiredFields, fn($var, $f) => AST::assign($var, $f->type->defaultValue())),
                AST::assign($operationResponse, AST::call($serviceClient, AST::method($method->methodName))($callVars)),
                $operationResponse->pollUntilComplete(),
                $useResponseFn($operationResponse),
                '// Alternatively:',
                '// start the operation, keep the operation name, and resume later',
                AST::assign($operationResponse, AST::call($serviceClient, AST::method($method->methodName))($callVars)),
                AST::assign($operationName, $operationResponse->getName()),
                '// ... do other work',
                AST::assign($newOperationResponse, $serviceClient->resumeOperation($operationName, $method->methodName)),
                AST::while(AST::not($newOperationResponse->isDone()))(
                    '// ... do other work',
                    $newOperationResponse->reload()
                ),
                $useResponseFn($newOperationResponse)
            )->finally(
                AST::call($serviceClient, AST::method('close'))()
            )
        );
    }

    private function rpcMethodExamplePaginated(SourceFileContext $ctx, MethodDetails $method): AST
    {
        $serviceClient = AST::var($this->serviceDetails->clientVarName);
        $callVars = $method->requiredFields->map(fn($x) => AST::var($x->camelName));
        $pagedResponse = AST::var('pagedresponse');
        $page = AST::var('page');
        $element = AST::var('element');
        return AST::block(
            AST::assign($serviceClient, AST::new($ctx->type($this->serviceDetails->emptyClientType))()),
            AST::try(
                Vector::zip($callVars, $method->requiredFields, fn($var, $f) => AST::assign($var, $f->type->defaultValue())),
                '// Iterate over pages of elements',
                AST::assign($pagedResponse, AST::call($serviceClient, AST::method($method->methodName))($callVars)),
                AST::foreach($pagedResponse->iteratePages(), $page)(
                    AST::foreach($page, $element)(
                        '// doSomethingWith($element);'
                    )
                ),
                '// Alternatively:',
                '// Iterate through all elements',
                AST::assign($pagedResponse, AST::call($serviceClient, AST::method($method->methodName))($callVars)),
                AST::foreach($pagedResponse->iterateAllElements(), $element)(
                    '// doSomethingWith($element);'
                )
            )->finally(
                $serviceClient->close()
            )
        );
    }
}
