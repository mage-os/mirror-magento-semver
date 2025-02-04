<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\SemanticVersionChecker\Analyzer\DiXml;

use Magento\SemanticVersionChecker\Analyzer\AnalyzerInterface;
use Magento\SemanticVersionChecker\Node\VirtualType;
use Magento\SemanticVersionChecker\Operation\DiXml\VirtualTypeChanged;
use Magento\SemanticVersionChecker\Operation\DiXml\VirtualTypeRemoved;
use Magento\SemanticVersionChecker\Operation\DiXml\VirtualTypeToTypeChanged;
use Magento\SemanticVersionChecker\Registry\XmlRegistry;
use PHPSemVerChecker\Registry\Registry;
use PHPSemVerChecker\Report\Report;

/**
 * VirtualType
 * Performs comparison of <b>di.xml</b> and creates reports such as:
 * - virtual type removed
 */
class VirtualTypeAnalyzer implements AnalyzerInterface
{
    /**
     * @var Report
     */
    private $report;

    /**
     * @param Report $report
     */
    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    /**
     * Compared registryBefore and registryAfter find changes for di virtual types.
     *
     * @param XmlRegistry|Registry $registryBefore
     * @param XmlRegistry|Registry $registryAfter
     *
     * @return Report
     */
    public function analyze($registryBefore, $registryAfter)
    {
        $nodesBefore = $this->getVirtualTypeNode($registryBefore);
        $nodesAfter = $this->getVirtualTypeNode($registryAfter);

        if ($nodesBefore === $nodesAfter) {
            return $this->report;
        }

        foreach ($nodesBefore as $moduleName => $moduleNodes) {
            /* @var VirtualType $nodeBefore */
            $fileBefore = $registryBefore->mapping[XmlRegistry::NODES_KEY][$moduleName];

            // Check if $moduleName exists in $registryAfter->mapping[XmlRegistry::NODES_KEY]
            if (!isset($registryAfter->mapping[XmlRegistry::NODES_KEY][$moduleName])) {
                continue;
            }
            foreach ($moduleNodes as $name => $nodeBefore) {
                // search nodesAfter the by name
                $nodeAfter = $nodesAfter[$moduleName][$name] ?? false;
                $fileAfter = $registryAfter->mapping[XmlRegistry::NODES_KEY][$moduleName];
                if ($nodeAfter !== false && $nodeBefore !== $nodeAfter) {
                    /* @var VirtualType $nodeAfter */
                    $this->triggerNodeChange($nodeBefore, $nodeAfter, $fileBefore);
                    continue;
                }

                foreach ($nodesAfter as $newModuleNodes) {
                    foreach ($newModuleNodes as $nodeAfter) {
                        if ($nodeBefore->getName() !== $nodeAfter->getName()) {
                            continue;
                        }

                        $this->triggerNodeChange($nodeBefore, $nodeAfter, $fileBefore);
                        continue 3;
                    }
                }

                $finalPath = $this->convertClassNameToFilePath($fileAfter, $name, '.php');

                if (file_exists($finalPath)) {
                    $operation = new VirtualTypeToTypeChanged($fileBefore, $name);
                } else {
                    $operation = new VirtualTypeRemoved($fileBefore, $name);
                }
                $this->report->add('di', $operation);
            }
        }

        return $this->report;
    }

    /**
     *  Method to convert class name to file path
     *
     * @param string $filePath
     * @param string $className
     * @param string $extraString
     * @return string
     */
    private function convertClassNameToFilePath($filePath, $className, $extraString = ''): string
    {
        // Split the initial file path to get the base directory.
        $parts = explode('/', $filePath);
        $classParts = explode('\\', $className);

        // Find the common part between the file path and class name.
        $baseDirParts = [];
        foreach ($parts as $part) {
            $baseDirParts[] = $part;

            if (in_array($part, $classParts)) {
                break;
            }
        }

        // Reconstruct the base directory path.
        $baseDir = implode('/', $baseDirParts);

        // Replace namespace separators with directory separators in the class name.
        $classFilePath = str_replace('\\', '/', $className);

        $position = strpos($classFilePath, "/");

        if ($position !== false) {
            $classFilePath = substr($classFilePath, $position);
        }

        // Combine the base directory and class file path.
        $fullPath = rtrim($baseDir, '/')  . $classFilePath;


        // Append the extra string if provided.
        if ($extraString) {
            $fullPath .= $extraString;
        }
        return $fullPath;
    }


    /**
     * Return a filtered node list from type {@link VirtualType}
     *
     * @param XmlRegistry $xmlRegistry
     * @return VirtualType[]
     */
    private function getVirtualTypeNode(XmlRegistry $xmlRegistry): array
    {
        $virtualTypeNodeList = [];

        foreach ($xmlRegistry->getNodes() as $moduleName => $nodeList) {
            foreach ($nodeList as $node) {
                if ($node instanceof VirtualType === false) {
                    continue;
                }

                /** @var  VirtualType $node */
                $virtualTypeNodeList[$moduleName][$node->getName()] = $node;
            }
        }

        return $virtualTypeNodeList;
    }

    /**
     * Add node changed to report.
     *
     * @param VirtualType $nodeBefore
     * @param VirtualType $nodeAfter
     * @param string $beforeFilePath
     */
    private function triggerNodeChange(VirtualType $nodeBefore, VirtualType $nodeAfter, string $beforeFilePath): void
    {
        $bcFieldBefore = [
            'type' => $nodeBefore->getType(),
        ];
        $bcFieldAfter = [
            'type' => $nodeAfter->getType(),
        ];

        if ($bcFieldBefore === $bcFieldAfter && $nodeAfter->getScope() === 'global') {
            // scope was changed to global no breaking change
            return;
        }

        $bcFieldBefore['scope'] = $nodeBefore->getScope();
        $bcFieldAfter['scope'] = $nodeAfter->getScope();

        foreach ($bcFieldBefore as $fieldName => $valueBefore) {
            $valueAfter = $bcFieldAfter[$fieldName];
            $changed = false;
            switch ($fieldName) {
                case 'type':
                    $changed = $this->isTypeChanged($valueBefore, $valueAfter);
                    break;
                default:
                    $changed = $valueBefore !== $valueAfter;
                    break;
            }
            if ($changed) {
                $operation = new VirtualTypeChanged($beforeFilePath, $fieldName);
                $this->report->add('di', $operation);
            }
        }
    }

    /**
     * Trim leading backslashes and than compare types
     *
     * @param $typeBefore
     * @param $typeAfter
     * @return bool
     */
    private function isTypeChanged($typeBefore, $typeAfter): bool
    {
        return ltrim(trim($typeBefore), "\\") !== ltrim(trim($typeAfter), "\\");
    }
}
