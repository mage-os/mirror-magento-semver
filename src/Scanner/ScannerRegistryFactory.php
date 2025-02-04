<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\SemanticVersionChecker\Scanner;

use Magento\SemanticVersionChecker\ClassHierarchy\DependencyGraph;
use Magento\SemanticVersionChecker\Helper\Node as NodeHelper;
use Magento\SemanticVersionChecker\Parser\LessParser;
use Magento\SemanticVersionChecker\Registry\LessRegistry;
use Magento\SemanticVersionChecker\Registry\XmlRegistry;
use Magento\SemanticVersionChecker\ReportTypes;
use Magento\SemanticVersionChecker\Visitor\ApiClassVisitor;
use Magento\SemanticVersionChecker\Visitor\ApiInterfaceVisitor;
use Magento\SemanticVersionChecker\Visitor\ApiTraitVisitor;
use Magento\SemanticVersionChecker\Visitor\ParentConnector;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use Magento\SemanticVersionChecker\Visitor\NameResolver;
use PhpParser\Parser\Php7 as Parser;
use PHPSemVerChecker\Registry\Registry;
use PHPSemVerChecker\Visitor\ClassVisitor;
use PHPSemVerChecker\Visitor\FunctionVisitor;
use PHPSemVerChecker\Visitor\InterfaceVisitor;
use PHPSemVerChecker\Visitor\TraitVisitor;

class ScannerRegistryFactory
{
    /**
     * @return Scanner
     */
    private function buildFullScanner()
    {
        $registry    = new Registry();
        $parser      = new Parser(new Emulative());
        $traverser   = new NodeTraverser();
        $apiVisitors = [
            new NameResolver(),
            new ParentConnector(),
            new ClassVisitor($registry),
            new InterfaceVisitor($registry),
            new FunctionVisitor($registry),
            new TraitVisitor($registry),
        ];

        return new Scanner($registry, $parser, $traverser, $apiVisitors);
    }

    /**
     * @param DependencyGraph|null $dependencyGraph
     * @param DependencyGraph|null $dependencyGraphCompare
     * @return Scanner
     */
    private function buildApiScanner(
        ?DependencyGraph $dependencyGraph = null,
        ?DependencyGraph $dependencyGraphCompare = null
    ) {
        $registry    = new Registry();
        $parser      = new Parser(new Emulative());
        $traverser   = new NodeTraverser();
        $nodeHelper  = new NodeHelper();
        $apiVisitors = [
            new NameResolver(),
            new ParentConnector(),
            new ApiClassVisitor($registry, $nodeHelper, $dependencyGraph, $dependencyGraphCompare),
            new ApiInterfaceVisitor($registry, $nodeHelper, $dependencyGraph, $dependencyGraphCompare),
            new ApiTraitVisitor($registry, $nodeHelper, $dependencyGraph, $dependencyGraphCompare),
            new FunctionVisitor($registry),
        ];

        return new Scanner($registry, $parser, $traverser, $apiVisitors);
    }

    /**
     * @param DependencyGraph|null $dependencyGraph
     * @param DependencyGraph|null $dependencyGraphCompare
     * @param boolean              $mftf
     * @return array
     */
    public function create(?DependencyGraph $dependencyGraph = null, ?DependencyGraph $dependencyGraphCompare = null)
    {
        $moduleNameResolver = new ModuleNamespaceResolver();

        return [
                ReportTypes::ALL => [
                    'pattern' => [
                        '*.php',
                    ],
                    'scanner' => $this->buildFullScanner(),
                ],
                ReportTypes::API => [
                    'pattern' => [
                        '*.php',
                    ],
                    'scanner' => $this->buildApiScanner($dependencyGraph, $dependencyGraphCompare),
                ],
                ReportTypes::DB_SCHEMA => [
                    'pattern' => [
                        'db_schema.xml',
                        'db_schema_whitelist.json',
                    ],
                    'scanner' => new DbSchemaScanner(new XmlRegistry(), $moduleNameResolver),
                ],
                ReportTypes::DI_XML => [
                    'pattern' => [
                        'di.xml'
                    ],
                    'scanner' => new DiConfigScanner(new XmlRegistry(), $moduleNameResolver),
                ],
                ReportTypes::LAYOUT_XML => [
                    'pattern' => [
                        '/view/*/*.xml'
                    ],
                    'scanner' => new LayoutConfigScanner(new XmlRegistry(), $moduleNameResolver),
                ],
                ReportTypes::SYSTEM_XML => [
                    'pattern' => [
                        'system.xml'
                    ],
                    'scanner' => new SystemXmlScanner(new XmlRegistry(), $moduleNameResolver),
                ],
                ReportTypes::XSD => [
                    'pattern' => [
                        '*.xsd'
                    ],
                    'scanner' => new XsdScanner(new XmlRegistry(), $moduleNameResolver),
                ],
                ReportTypes::LESS => [
                    'pattern' => [
                        '*.less'
                    ],
                    'scanner' => new LessScanner(new LessRegistry(), new LessParser(), $moduleNameResolver),
                ],
                ReportTypes::MFTF => [
                    'pattern' => [
                        '/Test/Mftf/*/*.xml'
                    ],
                    'scanner' => new MftfScanner(new XmlRegistry(), $moduleNameResolver),
                ],
                ReportTypes::ET_SCHEMA => [
                    'pattern' => [
                        'et_schema.xml'
                    ],
                    'scanner' => new EtSchemaScanner(
                        new XmlRegistry(),
                        $moduleNameResolver,
                        new EtSchema\XmlConverter()
                    ),
                ],
            ];
    }
}
