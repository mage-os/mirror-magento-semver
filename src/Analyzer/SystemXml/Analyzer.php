<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\SemanticVersionChecker\Analyzer\SystemXml;

use Magento\SemanticVersionChecker\Analyzer\AnalyzerInterface;
use Magento\SemanticVersionChecker\Node\SystemXml\Field;
use Magento\SemanticVersionChecker\Node\SystemXml\Group;
use Magento\SemanticVersionChecker\Node\SystemXml\NodeInterface;
use Magento\SemanticVersionChecker\Node\SystemXml\Section;
use Magento\SemanticVersionChecker\Operation\SystemXml\FieldAdded;
use Magento\SemanticVersionChecker\Operation\SystemXml\FieldRemoved;
use Magento\SemanticVersionChecker\Operation\SystemXml\FileAdded;
use Magento\SemanticVersionChecker\Operation\SystemXml\FileRemoved;
use Magento\SemanticVersionChecker\Operation\SystemXml\GroupAdded;
use Magento\SemanticVersionChecker\Operation\SystemXml\GroupRemoved;
use Magento\SemanticVersionChecker\Operation\SystemXml\SectionAdded;
use Magento\SemanticVersionChecker\Operation\SystemXml\SectionRemoved;
use Magento\SemanticVersionChecker\Registry\XmlRegistry;
use PHPSemVerChecker\Registry\Registry;
use PHPSemVerChecker\Report\Report;
use Magento\SemanticVersionChecker\Operation\SystemXml\FieldDuplicated;

/**
 * Analyzes <kbd>system.xml</kbd> files:
 * - Added and removed files
 * - Added and removed <kbd>section</kbd> nodes
 * - Added and removed <kbd>group</kbd> nodes
 * - Added and removed <kbd>field</kbd> nodes
 */
class Analyzer implements AnalyzerInterface
{
    /**
     * @var Report
     */
    private $report;

    /**
     * Constructor.
     *
     * @param Report $report
     */
    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    /**
     * Compare with a destination registry (what the new source code is like).
     *
     * @param XmlRegistry|Registry $registryBefore
     * @param XmlRegistry|Registry $registryAfter
     *
     * @return Report
     */
    public function analyze($registryBefore, $registryAfter)
    {
        $nodesBefore = $this->getNodes($registryBefore);
        $nodesAfter  = $this->getNodes($registryAfter);

        //bail out if there are no differences
        if ($nodesBefore === $nodesAfter) {
            return $this->report;
        }

        $modulesBefore = array_keys($nodesBefore);
        $modulesAfter  = array_keys($nodesAfter);

        //process added / removed files
        $addedModules   = array_diff($modulesAfter, $modulesBefore);
        $commonModules  = array_intersect($modulesBefore, $modulesAfter);
        $removedModules = array_diff($modulesBefore, $modulesAfter);

        //process added files
        $this->reportAddedFiles($addedModules, $registryAfter);

        //process removed files
        $this->reportRemovedFiles($removedModules, $registryBefore);

        //process common files
        foreach ($commonModules as $moduleName) {
            $moduleNodesBefore = $nodesBefore[$moduleName];
            $moduleNodesAfter  = $nodesAfter[$moduleName];
            $addedNodes        = array_diff_key($moduleNodesAfter, $moduleNodesBefore);
            $removedNodes      = array_diff_key($moduleNodesBefore, $moduleNodesAfter);
            if ($removedNodes) {
                $beforeFile = $registryBefore->mapping[XmlRegistry::NODES_KEY][$moduleName];
                $this->reportRemovedNodes($beforeFile, $removedNodes);
            }
            print_r('Added Nodes');
            print_r($addedNodes);
            if ($addedNodes) {
                $afterFile = $registryAfter->mapping[XmlRegistry::NODES_KEY][$moduleName];
                $duplicateNode = $this->reportAddedNodesWithDuplicateCheck($afterFile, $addedNodes, $moduleNodesBefore);
                print_r('Duplicate node '.$duplicateNode.' found');
                print_r("After file ". $afterFile );
                if ($duplicateNode) {
                    $this->reportDuplicateNodes($afterFile, $addedNodes);
                } else {
                    $this->reportAddedNodes($afterFile, $addedNodes);
                }
            }
        }
        return $this->report;
    }

    /**
     * Check and Report duplicate nodes
     *
     * @param $afterFile
     * @param $addedNodes
     * @param $moduleNodesBefore
     * @return bool|void
     */
    private function reportAddedNodesWithDuplicateCheck($afterFile, $addedNodes, $moduleNodesBefore)
    {
        print_r('Report Added Nodes function called.');
        foreach ($addedNodes as $nodeId => $node) {
            // Check for duplicates by comparing node content except for 'id'
            $isDuplicate = false;
            foreach ($moduleNodesBefore as $existingNodeId => $existingNode) {
                if ($this->isDuplicateNode($node, $existingNode)) {
                    $isDuplicate = true;
                    break;
                }
            }
            return $isDuplicate;
        }
    }

    /**
     * Check if node is duplicate or not
     *
     * @param $node
     * @param $existingNode
     * @return bool
     */
    private function isDuplicateNode($node, $existingNode)
    {
        // Access the 'id' properties using possible getter methods
        //Testing file
        
        $nodeId = $this->getPrivateProperty($node, 'id');
        $existingNodeId = $this->getPrivateProperty($existingNode, 'id');

        // Access 'parent' properties if needed, depending on your logic
        $nodeParent = $this->getPrivateProperty($node, 'parent');
        $existingNodeParent = $this->getPrivateProperty($existingNode, 'parent');

        // Compare the nodes after ignoring 'id'
        return $nodeParent == $existingNodeParent;
    }

    /**
     * Simplifies the reflection to get property
     *
     * @param $object
     * @param $propertyName
     * @return mixed
     * @throws \ReflectionException
     */
    private function getPrivateProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    /**
     * Extracts the node from <var>$registry</var> as an associative array.
     *
     * @param XmlRegistry $registry
     * @return array<string, array<string, NodeInterface>>
     */
    private function getNodes(XmlRegistry $registry): array
    {
        $nodes = [];

        foreach ($registry->getNodes() as $moduleName => $moduleNodes) {
            if (!isset($nodes[$moduleName])) {
                $nodes[$moduleName] = [];
            }

            /** @var NodeInterface $moduleNode */
            foreach ($moduleNodes as $moduleNode) {
                $nodeKey                      = $moduleNode->getUniqueKey();
                $nodes[$moduleName][$nodeKey] = $moduleNode;
            }
        }

        return $nodes;
    }

    /**
     * Creates reports for <var>$modules</var> considering that <kbd>system.xml</kbd> has been added to them.
     *
     * @param string[] $modules
     * @param XmlRegistry $registryAfter
     */
    private function reportAddedFiles(array $modules, XmlRegistry $registryAfter)
    {
        foreach ($modules as $module) {
            $afterFile = $registryAfter->mapping[XmlRegistry::NODES_KEY][$module];
            $this->report->add('system', new FileAdded($afterFile, 'system.xml'));
        }
    }

    /**
     * Creates reports for <var>$nodes</var> considering that they have been added.
     *
     * @param string $file
     * @param NodeInterface[] $nodes
     */
    private function reportAddedNodes(string $file, array $nodes)
    {
        foreach ($nodes as $node) {
            switch (true) {
                case $node instanceof Section:
                    $this->report->add('system', new SectionAdded($file, $node->getPath()));
                    break;
                case $node instanceof Group:
                    $this->report->add('system', new GroupAdded($file, $node->getPath()));
                    break;
                case $node instanceof Field:
                    $this->report->add('system', new FieldAdded($file, $node->getPath()));
                    break;
                default:
                    //NOP - Unknown node types are simply ignored as we do not validate
            }
        }
    }

    /**
     * Creates reports for <var>$nodes</var> considering that they have been duplicated.
     *
     * @param string $file
     * @param NodeInterface[] $nodes
     */
    private function reportDuplicateNodes(string $file, array $nodes)
    {
        print_r('Duplicate Nodes switch case');
        foreach ($nodes as $node) {
            echo "<br/> $node->getPath() <br/>";
            switch (true) {
                case $node instanceof Field:
                    $this->report->add('system', new FieldDuplicated($file, $node->getPath()));
                    break;
                default:
            }
        }
    }

    /**
     * Creates reports for <var>$modules</var> considering that <kbd>system.xml</kbd> has been removed from them.
     *
     * @param array $modules
     * @param XmlRegistry $registryBefore
     */
    private function reportRemovedFiles(array $modules, XmlRegistry $registryBefore)
    {
        foreach ($modules as $module) {
            $beforeFile = $registryBefore->mapping[XmlRegistry::NODES_KEY][$module];
            $this->report->add('system', new FileRemoved($beforeFile, 'system.xml'));
        }
    }

    /**
     * Creates reports for <var>$nodes</var> considering that they have been removed.
     *
     * @param string $file
     * @param NodeInterface[] $nodes
     */
    private function reportRemovedNodes(string $file, array $nodes)
    {
        foreach ($nodes as $node) {
            switch (true) {
                case $node instanceof Section:
                    $this->report->add('system', new SectionRemoved($file, $node->getPath()));
                    break;
                case $node instanceof Group:
                    $this->report->add('system', new GroupRemoved($file, $node->getPath()));
                    break;
                case $node instanceof Field:
                    $this->report->add('system', new FieldRemoved($file, $node->getPath()));
                    break;
                default:
                    //NOP Unknown node type
            }
        }
    }
}
