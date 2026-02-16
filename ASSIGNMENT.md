# PHP Developer - Take-Home Assignment

## Overview

You'll extend an existing fuel transaction API to add GPS enrichment from the Mapon telematics platform, and review the existing codebase for issues, patterns, and areas you'd improve.

**Estimated time:** 1-2 hours, possibly quicker

**What we're assessing:**
- Architectural thinking - how does this fit into the bigger picture?
- Critical evaluation - can you identify issues and form opinions about existing code?
- Edge case handling - what happens when things go wrong?
- Code quality - is your implementation consistent with existing patterns?
- Ownership mindset - how would you change the existing codebase, what would you update?

## Using AI/LLM Tools

**We encourage the use of AI coding assistants** (Claude, ChatGPT, Copilot, Cursor, etc.) for this assignment.

What matters to us:
- **Understanding** - Can you explain your code and the decisions made?
- **Architecture** - Did you think about how this fits into the larger system?
- **Quality** - Is the final result clean, working, and well-structured?

In the follow-up interview, we'll discuss your implementation. Be prepared to explain your approach, trade-offs considered, and how you'd extend or improve it.

## Background

Fleet managers import fuel card transactions into our system. Each transaction shows when and where a vehicle purchased fuel. We want to enrich these transactions with GPS coordinates from Mapon to verify the vehicle's actual location at purchase time.

## Setup

```bash
composer install
cp .env.example .env
php bin/setup.php
php -S localhost:8000 -t public public/router.php
```

Open http://localhost:8000 - you should see an empty transaction list.
**Test the import:** Try importing the sample CSV from `sample-data/fuel_transactions.csv`

## Your Tasks

### 1. Implement enrichment endpoints

Create two RPC endpoints that enrich fuel transactions with GPS data from the Mapon API. See [Mapon API Reference](#mapon-api-reference) below for API details.

**`/rpc/transaction/enrich`** - Single transaction enrichment

- **Input:** `id` (int, required) - Transaction ID to enrich
- **Behavior:** Fetch the transaction, call Mapon API to get GPS position at the transaction time, update the transaction with coordinates, odometer, and enrichment status
- **Output:** Return the enrichment result with the updated transaction data

**`/rpc/transaction/enrichAll`** - Batch enrichment

- **Input:** `limit` (int, optional) - Maximum number of transactions to process
- **Behavior:** Process transactions with `pending` enrichment status, handle partial failures gracefully, consider what happens with rate limits or API errors mid-batch
- **Output:** Return a summary of the batch operation results

**Requirements:**
1. **Implement `MaponClient`** - Complete the stub to call the Mapon API
2. **Create `Enrich.php` endpoint** - Single transaction enrichment
3. **Create `EnrichAll.php` endpoint** - Batch enrichment with proper error handling
4. **Handle edge cases gracefully** - Think about what can go wrong

### 2. Review the existing codebase

This is an equally important part of the assignment. As you work through the implementation, critically evaluate the existing code as if you were onboarding onto a real project. We expect you to identify real issues and form opinions.

Take note of:
- **Bugs or incorrect behavior** - Does the existing code work correctly? Are there logic errors, missing validations, or broken flows?
- **Data quality concerns** - Does the imported data look right? Are there records that shouldn't be there?
- **Code smells and maintainability** - Are there patterns that would cause problems at scale? Hardcoded values, overly complex logic, mixed responsibilities?
- **Naming and structure** - Does the project organization make sense? Are things named clearly and consistently?
- **Security concerns** - Is there anything that wouldn't be acceptable in a production environment?
- **How you'd approach it differently** - If you were building this from scratch or taking ownership, what would you change?

Write these observations in your `NOTES.md` - we value honest, thoughtful critique. We'll discuss them in the follow-up interview.

### Bonus (Optional)

**Duplicate prevention:** The current import allows the same CSV to be imported multiple times, creating duplicate transactions. Implement logic to detect and skip duplicates during import.

Consider: What fields define a "duplicate" transaction?

---

## Mapon API Reference

### What's Already Provided

- `mapon_unit_id` field is populated from the `vehicles` table during import
- `MaponClient` stub in `src/Domain/Mapon/` (you need to implement the API call)
- `MaponUnitData` DTO for API responses
- `MaponApiException` for error handling
- Transaction model with enrichment helper methods
- `Vehicle` model with lookup helpers

### API Details

**Documentation:** https://mapon.com/api/

**API Key:** Provided in the assignment PDF

**Endpoint:** `GET /unit_data/history_point.json`

Gets vehicle position and odometer at a specific point in time.

**Parameters:**
- `key` - API key
- `unit_id` - Vehicle unit ID (from `transaction.mapon_unit_id`)
- `datetime` - ISO 8601 timestamp with Z suffix (e.g., `2025-01-15T08:30:00Z`)
- `include[]` - Data to include: `position`, `mileage`

**Example Request:**
```
GET https://mapon.com/api/v1/unit_data/history_point.json?key=YOUR_API_KEY&unit_id=199332&datetime=2025-01-15T08:30:00Z&include[]=position&include[]=mileage
```

**Notes:**
- Datetime must use `Z` suffix for UTC
- Returns `404` if no data found for the requested time
- Returns `401` for invalid API key
- Response contains position coordinates and mileage data - refer to Mapon documentation for structure

### Hints

- Look at existing code in `src/Rpc/Section/Transaction/` for patterns
- The transaction model has helper methods you might find useful
- The frontend has buttons to test your endpoints

---

## Things to Think About

Beyond the implementation, we expect you to form opinions about the project as a whole. Think of this as a code review of an inherited codebase - we want to hear your honest assessment.

**Implementation concerns:**
- **Batch processing**: How do you handle failures mid-batch? Stop everything or continue?
- **Reliability**: What happens if the Mapon API is slow, returns errors, or times out?
- **Idempotency**: What if someone calls enrich twice on the same transaction?
- **Testing**: How would you test the Mapon integration without hitting the real API?

**Codebase critique (we especially value your thoughts here):**
- **Import flow**: Look at the `ImportService` closely. Does it behave correctly? Are there issues with the data it produces? What patterns would you change?
- **Data integrity**: After importing the sample CSV, does the data look correct? Are there records that seem wrong or unexpected?
- **Project structure**: What do you think about how the code is organized - directory structure, naming conventions, separation of concerns?
- **Security**: Are there any security concerns you'd flag if this were heading to production?
- **Overall architecture**: If you were taking ownership of this project, what are the first things you'd want to fix or refactor?

These observations are a significant part of what we assess. Write your findings in `NOTES.md` - be specific and honest.

## Submission

1. Create a git repository with your changes
2. Include a `NOTES.md` with:
   - How to test your implementation
   - Any assumptions you made
   - Architectural considerations and trade-offs
   - **Issues you found** in the existing codebase (bugs, code smells, security concerns, data quality problems)
   - **What you'd change** if you were taking ownership of the project

## What Happens Next

In the follow-up interview, we'll:
- Walk through your implementation together
- Discuss your design decisions and trade-offs
- Explore the architectural questions above
- Talk about what you liked and didn't like about the codebase

---

Questions? Make reasonable assumptions and note them in NOTES.md.
