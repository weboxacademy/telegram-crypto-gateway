# WeBox Telegram Crypto Gateway for WooCommerce

A WooCommerce payment gateway for accepting cryptocurrency payments through **Telegram Crypto Pay**.

The plugin allows WooCommerce stores to create Crypto Pay invoices in USD and redirect customers to the Telegram Crypto payment flow.

## Features

- 💳 WooCommerce payment gateway integration
- 💎 Cryptocurrency payments through Telegram Crypto Pay
- 💵 USD fiat-based invoices
- ₮ USDT payment support
- 💎 TON payment support
- ₿ BTC payment support
- 🔔 Webhook-based payment verification
- 🔐 Webhook signature verification
- 🛒 Automatic WooCommerce order handling
- ⚡ Direct Crypto Bot invoice flow
- 🧪 Admin connection testing
- 📝 Optional debug logging
- 📦 WooCommerce HPOS compatibility
- 🔄 Invoice metadata stored with WooCommerce orders
- 🔒 API credentials are stored in WooCommerce gateway settings

## How It Works

The payment flow is designed to be simple:

```text
Customer
   ↓
WooCommerce Checkout
   ↓
Pay with Telegram Crypto
   ↓
Crypto Pay Invoice
   ↓
Telegram Crypto Bot
   ↓
Customer selects a supported cryptocurrency
   ↓
Payment
   ↓
Crypto Pay Webhook
   ↓
WooCommerce Order Update