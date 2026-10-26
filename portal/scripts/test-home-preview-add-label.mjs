#!/usr/bin/env node
/**
 * Regression: home strips embed packagesAvailable:0 when quantity is hidden.
 * syncCartQtyFromDom → applyCartQtyToPayload must not flip sellable items to «مكتمل».
 *
 * Mirrors applyCartQtyToPayload in public/assets/store-product-preview.js
 */
import assert from 'node:assert/strict';
import test from 'node:test';

function applyCartQtyToPayload(p, inCart) {
  const next = { ...p, cartQty: inCart };
  if (next.effectiveMax == null && next.maxPackages == null) {
    return next;
  }

  const policyRemaining = next.maxPackages != null
    ? Math.max(0, Number(next.maxPackages) - inCart)
    : null;
  const stockRemaining = next.showQuantity && next.packagesAvailable != null
    ? Math.max(0, Number(next.packagesAvailable))
    : null;

  let effective = policyRemaining;
  if (stockRemaining !== null) {
    effective = effective !== null ? Math.min(effective, stockRemaining) : stockRemaining;
  } else if (policyRemaining !== null && !next.showQuantity) {
    const prevCart = Math.max(0, Number(p.cartQty) || 0);
    const prevEff = p.effectiveMax != null && Number.isFinite(Number(p.effectiveMax))
      ? Math.max(0, Number(p.effectiveMax))
      : null;
    if (prevEff !== null) {
      const prevPolicy = Math.max(0, Number(next.maxPackages) - prevCart);
      if (prevEff < prevPolicy) {
        effective = Math.min(policyRemaining, prevEff);
      }
    }
  }

  if (effective !== null) {
    next.effectiveMax = effective;
    next.remaining = effective;
    next.atLimit = effective <= 0;
  }
  return next;
}

test('hidden quantity with sellable effectiveMax stays إضافة (not مكتمل)', () => {
  const payload = {
    showQuantity: false,
    packagesAvailable: 0,
    effectiveMax: 2,
    remaining: 2,
    atLimit: false,
    maxPackages: null,
    cartQty: 0,
  };
  const next = applyCartQtyToPayload(payload, 0);
  assert.equal(next.atLimit, false);
  assert.equal(next.effectiveMax, 2);
});

test('visible zero stock still marks atLimit', () => {
  const payload = {
    showQuantity: true,
    packagesAvailable: 0,
    effectiveMax: 0,
    remaining: 0,
    atLimit: true,
    maxPackages: null,
    cartQty: 0,
  };
  const next = applyCartQtyToPayload(payload, 0);
  assert.equal(next.atLimit, true);
  assert.equal(next.effectiveMax, 0);
});

test('hidden quantity keeps exhausted stock when policy max would reopen', () => {
  const payload = {
    showQuantity: false,
    packagesAvailable: 0,
    effectiveMax: 0,
    remaining: 0,
    atLimit: true,
    maxPackages: 20,
    cartQty: 0,
  };
  const next = applyCartQtyToPayload(payload, 0);
  assert.equal(next.atLimit, true);
  assert.equal(next.effectiveMax, 0);
});

test('hidden quantity with policy max preserves stricter stock cap', () => {
  const payload = {
    showQuantity: false,
    packagesAvailable: 0,
    effectiveMax: 2,
    remaining: 2,
    atLimit: false,
    maxPackages: 20,
    cartQty: 0,
  };
  const next = applyCartQtyToPayload(payload, 0);
  assert.equal(next.atLimit, false);
  assert.equal(next.effectiveMax, 2);
});
