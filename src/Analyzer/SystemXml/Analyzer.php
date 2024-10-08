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
use Magento\SemanticVersionChecker\Operation\SystemXml\DuplicateFieldAdded;
use RecursiveDirectoryIterator;

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

            if ($addedNodes) {
                $afterFile = $registryAfter->mapping[XmlRegistry::NODES_KEY][$moduleName];
                if (strpos($afterFile, '_files') !== false) {
                    $this->reportAddedNodes($afterFile,$addedNodes);
                } else {
                    $baseDir = $this->getBaseDir($afterFile);
                    foreach ($addedNodes as $nodeId => $node) {
                        $newNodeData = $this->getNodeData($node);
                        $nodePath = $newNodeData['path'];

                        // Extract section, group, and fieldId with error handling
                        $extractedData = $this->extractSectionGroupField($nodePath);
                        if ($extractedData === null) {
                            // Skip the node if its path is invalid
                            continue;
                        }

                        // Extract section, group, and fieldId
                        list($sectionId, $groupId, $fieldId) = $extractedData;

                        // Call function to check if this field is duplicated in other system.xml files
                        $isDuplicated = $this->isDuplicatedFieldInXml($baseDir, $sectionId, $groupId, $fieldId, $afterFile);

                        foreach ($isDuplicated as $isDuplicatedItem) {
                            if ($isDuplicatedItem['status'] === 'duplicate') {
                                $this->reportDuplicateNodes($afterFile, [$nodeId => $node]);
                            } else {
                                $this->reportAddedNodes($afterFile, [$nodeId => $node]);
                            }
                        }
                    }
                }
            }
        }
        return $this->report;
    }

    /**
     * Get Magento Base directory from the path
     *
     * @param string $filePath
     * @return string|null
     */
    private function getBaseDir($filePath)
    {
        $currentDir = dirname($filePath);
        while ($currentDir !== '/' && $currentDir !== false) {
            // Check if current directory contains files unique to Magento root
            if (file_exists($currentDir . '/SECURITY.md')) {
                return $currentDir; // Found the Magento base directory
            }
            $currentDir = dirname($currentDir);
        }
        return null;
    }

    /**
     * Search for system.xml files in both app/code and vendor directories, excluding the provided file.
     *
     * @param string $magentoBaseDir The base directory of Magento.
     * @param string $excludeFile The file to exclude from the search.
     * @return array An array of paths to system.xml files, excluding the specified file.
     */
    private function getSystemXmlFiles($magentoBaseDir, $excludeFile = null)
    {
        $systemXmlFiles = [];
        $directoryToSearch = [
            $magentoBaseDir.'/app/code'
        ];

        // Check if 'vendor' directory exists, and only add it if it does
        if (is_dir($magentoBaseDir . '/vendor')) {
            $directoriesToSearch[] = $magentoBaseDir . '/vendor';
        }
        foreach ($directoryToSearch as $directory) {
            $iterator = new \RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if ($file->getfileName() === 'system.xml') {
                    $filePath = $file->getRealPath();
                    if ($filePath !== $excludeFile) {
                        $systemXmlFiles[] = $file->getRealPath();
                    }
                }
            }
        }
        return $systemXmlFiles;
    }

    /**
     * Method to extract section, group and field from the Node
     *
     * @param $nodePath
     * @return array|null
     */
    private function extractSectionGroupField($nodePath)
    {
        $parts = explode('/', $nodePath);

        if (count($parts) < 3) {
            // Invalid path if there are fewer than 3 parts
            return null;
        }

        $sectionId = $parts[0];
        $groupId = $parts[1];
        $fieldId = $parts[2];

        return [$sectionId, $groupId, $fieldId];
    }

    /**
     * Method to get Node Data using reflection class
     *
     * @param $node
     * @return array
     * @throws \ReflectionException
     */
    private function getNodeData($node)
    {
        $data = [];

        // Use reflection to get accessible properties
        $reflection = new \ReflectionClass($node);
        foreach ($reflection->getMethods() as $method) {
            // Skip 'getId' and 'getParent' methods for comparison
            if ($method->getName() === 'getId' || $method->getName() === 'getParent') {
                continue;
            }

            // Dynamically call the getter methods
            if (strpos($method->getName(), 'get') === 0) {
                $propertyName = lcfirst(str_replace('get', '', $method->getName()));
                $data[$propertyName] = $method->invoke($node);
            }
        }

        return $data;
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
        foreach ($nodes as $node) {
            switch (true) {
                case $node instanceof Field:
                    $this->report->add('system', new DuplicateFieldAdded($file, $node->getPath()));
                    break;
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

    /**
     * @param string|null $baseDir
     * @param string $sectionId
     * @param string $groupId
     * @param string $fieldId
     * @param string $afterFile
     * @return array
     * @throws \Exception
     */
    private function isDuplicatedFieldInXml(?string $baseDir, string $sectionId, string $groupId, ?string $fieldId, string $afterFile): array
    {
        $hasDuplicate = false;

        $result = [
            'status' => 'minor',
            'field'  => $fieldId
        ];

        if ($baseDir) {
            $systemXmlFiles = $this->getSystemXmlFiles($baseDir, $afterFile);

            foreach ($systemXmlFiles as $systemXmlFile) {
                $xmlContent = file_get_contents($systemXmlFile);
                try {
                    $xml = new \SimpleXMLElement($xmlContent);
                } catch (\Exception $e) {
                    continue; // Skip this file if there's a parsing error
                }
                // Find <field> nodes with the given field ID
                // XPath to search for <field> within a specific section and group
                $fields = $xml->xpath("//section[@id='$sectionId']/group[@id='$groupId']/field[@id='$fieldId']");
                if (!empty($fields)) {
                    $hasDuplicate = true; // Set the duplicate flag to true if a match is found
                    break; // Since we found a duplicate, we don't need to check further for this field
                }
            }
            if ($hasDuplicate) {
                return [
                    [
                        'status' => 'duplicate',
                        'field'  => $fieldId

                    ]
                ];
            }
        }
        return [$result];
    }
}
