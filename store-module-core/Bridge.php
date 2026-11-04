<?php
/**
 * RentADriver store-module protocol v1: what the platform module provides (see Core.php and PROTOCOL.md).
 *
 * @license GPL-2.0-or-later
 */
namespace RentADriver\StoreModule;

/** What the platform module provides. Config values are strings; null = unset. */
interface Bridge
{
    public function platform();
    public function moduleVersion();
    public function platformVersion();
    public function getConfig($key);
    /** @param array $values key => string|null (null deletes) */
    public function setConfig(array $values);
    /** Store name, email, currency, timezone, address {line, city, country_code, phone}, weight_unit. */
    public function profile();
    /** One order in "rd order v1" shape, or null. $ref is the platform order id (or its display number). */
    public function order($ref);
    /** Recent open orders (paid or awaiting payment), newest first, "rd order v1" shape. */
    public function orders($limit);
    /**
     * Add an order-history entry. $status: null (keep the status), "shipped" or "delivered" (the module's mapped
     * statuses). $tracking: ['number' => ..., 'url' => ...]. Returns an id for the entry.
     */
    public function addHistory($orderId, $comment, $notify, $status, array $tracking);
}
