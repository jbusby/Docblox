<?php
/**
 * DocBlox
 *
 * @category   DocBlox
 * @package    Base
 * @copyright  Copyright (c) 2010-2010 Mike van Riel / Naenius. (http://www.naenius.com)
 */

/**
 * Class diagram generator.
 *
 * Checks whether graphviz is enabled and logs an error if not.
 *
 * @category   DocBlox
 * @package    Base
 * @author     Mike van Riel <mike.vanriel@naenius.com>
 */
class DocBlox_Writer_Xslt_ClassGraph extends DocBlox_Writer_Xslt_Abstract
{
  public function execute(DomDocument $xml)
  {
    $path = realpath($this->target);

    // prepare the xsl document
    $xsl  = new DOMDocument;
    $proc = new XSLTProcessor();
    $xsl->load($this->getThemePath() . '/index.xsl');
    $proc->importStyleSheet($xsl); // attach the xsl rules
    $proc->setParameter('', 'title', 'Classes');
    $this->transformTemplateToFile($xml, $proc, '../index.html');

    // NOTE: the -V flag sends output using STDERR and STDOUT
    exec('dot -V 2>&1', $output, $error);
    if ($error != 0)
    {
      $this->log('Unable to find the `dot` command of the GraphViz package. Is GraphViz correctly installed and present in your path?', Zend_Log::ERR);
      return;
    }

    // generate graphviz
    $xpath = new DOMXPath($xml);
    $qry = $xpath->query("/project/file/class");

    $extend_classes = array();
    $classes = array();

    /** @var DOMElement $element */
    foreach ($qry as $element)
    {
      $extends = $element->getElementsByTagName('extends')->item(0)->nodeValue;
      if (!$extends)
      {
        $extends = 'stdClass';
      }

      if (!isset($extend_classes[$extends]))
      {
        $extend_classes[$extends] = array();
      }

      $extend_classes[$extends][] = $element->getAttribute('namespace') . '\\' . $element->getElementsByTagName('name')->item(0)->nodeValue;
      $classes[] = $element->getElementsByTagName('name')->item(0)->nodeValue;
    }

    // find root nodes, (any class not found as extend)
    foreach ($extend_classes as $extend => $class_list)
    {
      if (!in_array($extend, $classes))
      {
        // if the extend is not in the list of classes (i.e. stdClass) then we have a root node
        $root_nodes[] = $extend;
      }
    }

    // traverse root nodes upwards
    $tree['stdClass'] = $this->buildTreenode($extend_classes);
    foreach ($root_nodes as $node)
    {
      if ($node === 'stdClass')
      {
        continue;
      }

      if (!isset($tree['stdClass']['?']))
      {
        $tree['stdClass']['?'] = array();
      }

      $tree['stdClass']['?'][$node] = $this->buildTreenode($extend_classes, $node);
    }

    $graph = new Image_GraphViz(true, array('rankdir' => 'RL', 'splines' => true, 'concentrate' => 'true', 'ratio' => '0.9'), 'Classes');
    $this->buildGraphNode($graph, $tree);
    $dot_file = $graph->saveParsedGraph();
    $graph->renderDotFile($dot_file, $path.'/classes.svg');
  }

  protected function buildTreenode($node_list, $parent = 'stdClass', $chain = array())
  {
    if (count($chain) > 50)
    {
      $path = implode(' => ', array_slice($chain, -10));
      $this->log('Maximum nesting level reached of 50, last 10 classes in the hierarchy path: '.$path, Zend_Log::WARN);
      return array();
    }

    if (!isset($node_list[$parent]))
    {
      return array();
    }

    $result = array();
    $chain[] = $parent;
    foreach($node_list[$parent] as $node)
    {
      $result[$node] = $this->buildTreenode($node_list, $node, $chain);
    }

    return $result;
  }

  protected function buildGraphNode(Image_GraphViz $graph, $nodes, $parent = null)
  {
    foreach($nodes as $node => $children)
    {
      $node_array = explode('\\', $node);
      $graph->addNode(md5($node), array('label' => end($node_array), 'shape' => 'box'));
      if ($parent !== null)
      {
        $graph->addEdge(array(md5($node) => md5($parent)), array('arrowhead' => 'empty', 'minlen' => '2'));
      }
      $this->buildGraphNode($graph, $children, $node);
    }
  }

}
