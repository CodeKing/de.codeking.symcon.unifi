<?php

class Kint_Parser_Binary extends Kint_Parser_Plugin
{
    public function getTypes()
    {
        return ['string'];
    }

    public function getTriggers()
    {
        return Kint_Parser::TRIGGER_SUCCESS;
    }

    public function parse(&$var, Kint_Object &$o, $trigger)
    {
        if (!$o instanceof Kint_Object_Blob || !in_array($o->encoding, ['ASCII', 'UTF-8'])) {
            $o->value->hints[] = 'binary';
        }
    }
}
