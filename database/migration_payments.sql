-- ---------------------------------------------------------------------
-- Payments, for the PayMongo integration.
--
-- A payment is its own record rather than extra columns on orders: an order
-- can be attempted more than once (abandoned, failed, retried), and each
-- attempt has its own provider reference, amount and outcome. Flattening that
-- into the order row would lose every attempt but the last, which is exactly
-- the history you need when a customer says they were charged.
--
-- No card number, no CVV, no cardholder data of any kind is stored here, and
-- none ever reaches this server: the customer types their card on PayMongo's
-- hosted page. What is kept is the reference PayMongo gave us, what we asked
-- them to charge, and what they told us happened.
--
-- Additive and idempotent. No existing row is modified or removed.
-- ---------------------------------------------------------------------
USE markme_db;

CREATE TABLE IF NOT EXISTS payments (
    payment_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    provider        VARCHAR(30)  NOT NULL DEFAULT 'paymongo',

    -- PayMongo's checkout session id (cs_...). Unique, so a duplicate webhook
    -- or a double-clicked button cannot produce a second payment row for the
    -- same session - the insert simply loses the race and is ignored.
    provider_reference VARCHAR(100) NOT NULL,

    -- The amount we asked PayMongo to charge, in centavos, exactly as it was
    -- sent. Kept so the figure can be compared against what they report back
    -- rather than trusted from the callback.
    amount_centavos BIGINT UNSIGNED NOT NULL,
    currency        CHAR(3)      NOT NULL DEFAULT 'PHP',

    -- Our own reading of where the payment stands. 'pending' until a verified
    -- response says otherwise; nothing moves it but server-side verification.
    status          ENUM('pending','paid','failed','cancelled','expired')
                    NOT NULL DEFAULT 'pending',

    -- What the provider called it, kept verbatim for investigation. Their
    -- vocabulary is theirs and may grow; ours above stays small on purpose.
    provider_status VARCHAR(40)  NULL,

    -- The payment id inside the session once one exists (pay_...).
    provider_payment_id VARCHAR(100) NULL,

    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    -- When a server-to-server check last confirmed this. A row with no
    -- verified_at has never been confirmed by anything but our own request.
    verified_at     TIMESTAMP    NULL,

    CONSTRAINT fk_payments_order FOREIGN KEY (order_id)
        REFERENCES orders(order_id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_payments_reference (provider_reference),
    KEY idx_payments_order (order_id),
    KEY idx_payments_status (status)
) ENGINE=InnoDB;

-- Every provider event we accept, so a repeated delivery is recognised as one
-- we have already handled rather than applied twice.
CREATE TABLE IF NOT EXISTS payment_events (
    event_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider        VARCHAR(30)  NOT NULL DEFAULT 'paymongo',
    -- PayMongo's event id (evt_...). Unique: the second delivery of the same
    -- event cannot insert, which is what makes handling idempotent.
    provider_event_id VARCHAR(100) NOT NULL,
    event_type      VARCHAR(60)  NOT NULL,
    payment_id      INT UNSIGNED NULL,
    received_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_payment_events (provider, provider_event_id),
    CONSTRAINT fk_payment_events_payment FOREIGN KEY (payment_id)
        REFERENCES payments(payment_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Card and e-wallet payments go through PayMongo. The two existing methods are
-- untouched, so every order already placed keeps its meaning.
ALTER TABLE orders
    MODIFY COLUMN payment_method
        ENUM('Cash on Delivery','Bank Transfer','PayMongo') NOT NULL;
