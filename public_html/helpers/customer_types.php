<?php

function normalize_customer_type(?string $value): string
{
    $v = strtolower(trim((string)$value));
    if (in_array($v, ['personal', 'professional', 'commercial'], true)) {
        return $v;
    }
    return 'personal';
}

function customer_type_label(string $type): string
{
    $type = normalize_customer_type($type);
    if ($type === 'professional') return 'Professional (Business Buyer)';
    if ($type === 'commercial') return 'Commercial (Business)';
    return 'Personal';
}
