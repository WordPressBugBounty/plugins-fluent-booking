<?php

namespace FluentBooking\Package\CalDav\ICal;

use DOMDocument;
use DOMXPath;

class XmlParser
{
	public static function parse($xml)
	{
		$instance = new static;
		
		$doc = new DOMDocument();

        $doc->loadXML($xml);

        $xpath = new DOMXPath($doc);

        $xpath->registerNamespace('d', 'DAV:');

        $list = [];

        foreach ($xpath->query('//d:response') as $res) {
            $list[] = $instance->parseNodes($res);
        }

        return $list;
	}

	protected function parseNodes($x)
    {
        $nodes = [];

        foreach ($x->childNodes as $n) {
            if ($this->hasChild($n)) {
                $nodes[$n->localName][] = $this->parseNodes($n);
            } else if ($n->nodeType == XML_ELEMENT_NODE) {
                $nodes[$n->localName][] = $n->nodeValue;

                if ($n->hasAttributes()) {
                    foreach ($n->attributes as $attr) {
                        $nodes[$n->localName][]['attributers'][$attr->nodeName] = $attr->nodeValue;
                    }

                    $nodes[$n->localName] = array_values(array_filter($nodes[$n->localName]));
                }
            }
        }

        return $nodes;
    }

    protected function hasChild($n)
    {
        if ($n->hasChildNodes()) {
            foreach ($n->childNodes as $c) {
                if ($c->nodeType == XML_ELEMENT_NODE) {
                    return true;
                }
            }
        }

        return false;
    }
}
