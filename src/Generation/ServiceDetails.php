<?php declare(strict_types=1);

namespace Google\Generator\Generation;

use \Google\Protobuf\Internal\ServiceDescriptorProto;
use \Google\Generator\Utils\ProtoCatalog;
use \Google\Generator\Utils\ProtoHelpers;
use \Google\Generator\Utils\CustomOptions;
use \Google\Generator\Utils\Helpers;
use \Google\Generator\Collections\Vector;

class ServiceDetails {
    /** @var ProtoCatalog *Readonly* The proto-catalog containing all source protos. */
    public ProtoCatalog $catalog;

    /** @var string *Readonly* The PHP namespace of this service client. */
    public string $clientNamespace;

    /** @var string *Readonly* The name of the service client class. */
    public string $gapicClientClassName;

    public function __construct(ProtoCatalog $catalog, string $namespace, string $package, ServiceDescriptorProto $desc)
    {
        $this->catalog = $catalog;
        $this->clientNamespace = "{$namespace}\Gapic";
        $this->gapicClientClassName = "{$desc->getName()}GapicClient";
        // TODO: More details...
    }
}