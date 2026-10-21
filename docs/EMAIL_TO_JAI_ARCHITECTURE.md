# Reply email — send architecture document to Jai

Attach: `AMAZON_INTEGRATION_ARCHITECTURE.docx`

---

**To:** Jai Radhakrishnan  
**Cc:** Ed Petrovacki; Chris Bilton; Bhaumik Dhandhukia; Muhammad Basit Munir; Alyshia Digiovannantonio  
**Subject:** Re: High Tide – Amazon Business API Integration – October 14, 2026 Recap and Next Steps

---

Hi Jai,

Thanks for the note.

Please find attached the high-level architecture for the High Tide Amazon Business integration on **ccsupplies.ca**.

It covers:

- Internal systems — web/app (Pagely, nginx, PHP, WordPress / WooCommerce), application layer (WCSS + Amazon Catalog / Cart / Checkout), and database (MariaDB)
- How store employees and managers use the portal
- How we call Amazon Business APIs (Login with Amazon → Product Search → Cart → cost estimation → Ordering with Trial Mode)
- Three diagrams: system architecture, order flow, and stack / Amazon boundary

The details in the document were checked against the live production admin and the current code, so they match what is running today.

Happy to walk through it on a call, or expand any section if you need more detail.

Thanks,  
Usman Zahid  
Basecamp Consulting — High Tide / ccsupplies.ca  
uzahid@basecampconsulting.co
