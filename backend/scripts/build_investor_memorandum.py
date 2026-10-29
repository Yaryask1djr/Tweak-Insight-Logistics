import os
import sys

# Ensure backend/scripts is on sys.path
sys.path.append(os.path.dirname(__file__))

from docx_builder_core import DocxBuilder

def generate_full_memorandum():
    output_filename = "Tweak_Insight_Logistics_Investor_Pitch_&_Financial_Model.docx"
    doc = DocxBuilder(output_filename)

    # =========================================================================
    # COVER PAGE
    # =========================================================================
    doc.add_title_block(
        title="TWEAK INSIGHT LOGISTICS (TIL)",
        subtitle="Next-Generation Hyper-Local Logistics, Real-Time Dispatch & Commercial Fleet Infrastructure for Northern Nigeria and West Africa",
        metadata_dict={
            "Document Type": "Confidential Institutional Investment Memorandum & 5-Year Financial Model",
            "Target Audience": "Venture Capital Funds, Private Equity, Strategic Freight/Supply Chain Partners & Angel Syndicates",
            "Proposed Investment Round": "Seed / Series A Growth Round — NGN 750,000,000 (~$500,000 USD)",
            "Primary Operating Base": "Kano Metropolitan Commercial Corridor, Kano State, Federal Republic of Nigeria",
            "Current Tech Status": "Production-Ready Full Stack (Web PWA, Driver Portal, Admin Control Tower, Redis Telematics Engine)",
            "Corporate Entity": "Tweak Insight Logistics Ltd. (RC Registered)",
            "Date of Issuance": "Fourth Quarter 2026",
            "Classification": "STRICTLY PRIVATE & CONFIDENTIAL — DO NOT FORWARD"
        }
    )

    doc.add_callout(
        title="Investment Opportunity in Brief",
        text="Tweak Insight Logistics (TIL) has engineered and deployed a battle-tested, high-margin, cloud-native logistics operating system purpose-built for the $1.2B annual wholesale and commercial trade corridors of Kano State—West Africa's largest textile, agricultural, and FMCG trading hub. Operating an asset-light hybrid fleet model with an automated 65/35 driver-platform revenue split, TIL delivers 31.2% net contribution margins on day one, backed by real-time GPS telemetry, dual cryptographic OTP proof of delivery, multi-zone geofencing, and zero-leakage escrow settlement."
    )

    doc.add_page_break()

    # =========================================================================
    # SECTION 1: EXECUTIVE SUMMARY & THE INVESTMENT THESIS
    # =========================================================================
    doc.add_h1("1. Executive Summary & Investment Thesis")

    doc.add_h2("1.1 The Macroeconomic Landscape: Kano as the Epicenter of West African Trade")
    doc.add_p(
        "Kano State represents the single most dynamic commercial nexus in Sub-Saharan Northern Nigeria and the wider Sahelian trade bloc. With an urban population exceeding 15 million citizens and commercial trade corridors that supply neighboring landlocked nations (Niger Republic, Chad, and Northern Cameroon), Kano processes over 180,000 commercial freight movements each week across historic market concentrations including Kantin Kwari (Africa's largest textile market), Singer Market (packaged food and FMCG), Dawanau (the world's largest grain market), and Kurmi Market."
    )
    doc.add_p(
        "Despite this staggering commercial volume, over 93% of all parcel, cargo, and wholesale deliveries remain trapped in an informal, highly fragmented roadside dispatch system. Merchants lose an estimated 14% of high-value goods annually to cargo pilferage, misplacement, and delayed transit. Senders have zero visibility into driver locations, drivers suffer erratic daily income without digital settlement, and commercial disputes run rampant due to the absence of verifiable proof of delivery."
    )

    doc.add_h2("1.2 The Tweak Insight Logistics Solution")
    doc.add_p(
        "Tweak Insight Logistics solves this fundamental infrastructure gap through a proprietary, vertically integrated logistics operating platform connecting commercial traders, retail senders, and institutional enterprises with a verified network of two-wheel motorbikes, three-wheel tricycles (Keke cargo), and light commercial vans."
    )

    doc.add_bullet("Real-Time Telematics & Live Map Visibility", "Sub-second GPS streaming with moving bearing, route breadcrumbs, and live traffic-adjusted ETA calculation via battery-efficient background telemetry.")
    doc.add_bullet("Cryptographic Proof of Delivery (POD)", "Dual 6-digit cryptographic OTP handshake ensuring zero cargo release until verified by recipient, completely eliminating transit disputes and cargo fraud.")
    doc.add_bullet("Algorithmic Smart Dynamic Fare Calculator", "Multi-parameter pricing engine factoring base metro fare, distance thresholds, weight bands, fragile handling insurance, perishable surcharges, and congestion surge multipliers.")
    doc.add_bullet("65/35 Transparent Revenue Sharing Engine", "Market-leading earnings split providing 65% net trip gross to vetted delivery partners and 35% platform margin, fostering partner loyalty and high fulfillment rates.")
    doc.add_bullet("Multi-Zone Polygon Geofencing", "Six mapped LGA zones (Fagge, Dala, Gwale, Tarauni, Nasarawa, Kumbotso) backed by 8 strategically placed physical micro-hub depots.")
    doc.add_bullet("Enterprise B2B Logistics Suite", "Corporate sub-account hierarchy, consolidated 14/30-day billing, bulk CSV order ingestion, and dedicated API integration for regional merchants.")

    doc.add_h2("1.3 Core Investment Highlights")
    doc.add_table(
        headers=["Investment Pillar", "Tweak Insight Logistics Value Proposition", "Investor Significance"],
        rows=[
            ["Proven Unit Economics", "35% gross take rate delivering NGN 702.50 net contribution margin per average NGN 2,250 delivery (31.2% net margin).", "High gross profitability from inception; self-funding unit unit growth."],
            ["Negative Working Capital", "Client instant card/transfer prepayment vs. daily/weekly partner settlement creates permanent cash float.", "Minimal working capital drain; rapid cash conversion cycle."],
            ["Proprietary Tech Stack", "Full-stack IP: React PWA, PHP 8 REST micro-services, Redis telemetry queue, Leaflet geospatial mapping, Paystack escrow.", "Zero reliance on expensive foreign SaaS licenses; full customization agility."],
            ["Defensible Local Moat", "8 physical Kano operating depots, embedded relations with Kantin Kwari & Singer trader associations, Hausa-speaking driver support.", "Impenetrable local moat against generic national ride-hailing apps."],
            ["Massive Scalability", "Asset-light model seamlessly scales across Kano metropolitan, the Kano-Kaduna-Abuja corridor, and trans-Sahel trade.", "Clear trajectory from NGN 228.5M Year 1 revenue to NGN 15.75B in Year 5 (64.6% EBITDA)."]
        ],
        col_widths=[2200, 4360, 2800]
    )

    doc.add_page_break()

    # =========================================================================
    # SECTION 2: SYSTEM ARCHITECTURE & FULL TECHNICAL BREAKDOWN
    # =========================================================================
    doc.add_h1("2. Full System Architecture & Component Breakdown")
    doc.add_p(
        "Tweak Insight Logistics is engineered on a resilient, high-concurrency, enterprise-grade distributed software architecture. Every component has been built to function seamlessly under real-world West African operational constraints, including variable mobile network latency, extreme outdoor solar glare, and unbanked gig-worker environments."
    )

    doc.add_h2("2.1 High-Level Architecture Blueprint")
    doc.add_callout(
        title="5-Tier Architectural Topology",
        text="• Tier 1 (Presentation): Progressive Web Application (PWA) built on React 18, HTML5, CSS3, responsive mobile ergonomics, and High-Contrast Outdoor Sunlight Mode.\n• Tier 2 (API Gateway & Security): Micro-services routing layer with token-bucket rate limiting, dual-token JWT authentication, and strict Content Security Policy (CSP).\n• Tier 3 (Asynchronous Queue & Event Bus): In-memory Redis 7 message broker managing dispatch job broadcasts, driver telemetry ingestion, and asynchronous notification workers.\n• Tier 4 (Persistence & Dual-Ledger Data Store): High-availability MySQL 8 InnoDB engine with strict ACID foreign key constraints, keyset cursor pagination, and immutable audit triggers.\n• Tier 5 (Geospatial & Telematics Engine): OpenStreetMap vector tiling, Leaflet coordinate tracking, Euclidean/Haversine distance matrix calculators, and GPS compass heading normalizers."
    )

    doc.add_h2("2.2 Comprehensive Component & Feature Inventory")

    doc.add_h3("Component 1: Client Web Portal & Mobile PWA")
    doc.add_p(
        "The Client Web Portal is the primary digital interface for commercial senders, e-commerce retailers, and individual package owners across desktop, tablet, and mobile devices."
    )
    doc.add_bullet("Instant Algorithmic Price Quotation", "Live fare calculation prior to booking based on origin, destination LGA, cargo weight (kg), and special handling flags.")
    doc.add_bullet("Interactive Multi-Modal Route Setup", "Instant geocoding and landmark selection across mapped Kano markets, corporate plazas, and residential estates.")
    doc.add_bullet("Consignment Classification Engine", "One-click toggling for Standard Cargo, Fragile Glass/Electronics, Perishable Food/Pharmaceuticals, and High-Value Wholesale Textiles.")
    doc.add_bullet("Real-Time Telemetry Tracking Dashboard", "Live interactive OpenStreetMap showing driver movement, speed, heading, route breadcrumbs, and precise arrival countdown.")
    doc.add_bullet("Enterprise Address Directory", "Merchant saved address repository for recurring customer destinations, reducing repeat checkout time to under 15 seconds.")
    doc.add_bullet("Multi-Channel Digital Payments", "Integrated Paystack gateway supporting Nigerian debit cards, USSD, direct bank transfer, and pre-funded Corporate Wallet.")

    doc.add_h3("Component 2: Admin Central Operations Control Tower")
    doc.add_p(
        "The Admin Control Tower serves as the nerve center for company fleet coordinators, compliance supervisors, and financial controllers, providing total operational visibility."
    )
    doc.add_bullet("Live Operations Status Strip", "Real-time status indicators tracking active dispatch pulse, pending review queues, available fleet readiness count, and total gross volume.")
    doc.add_bullet("Interactive Kanban Dispatch Queue", "Visual state management across 10 operational delivery states: Pending, Under Review, Broadcasted, Assigned, Driver En Route, Picked Up, In Transit, Arrived, Delivered, and Completed.")
    doc.add_bullet("Live Fleet Telematics Radar", "Geospatial radar map plotting all active riders across Kano metro with online/offline status, battery level, speed, and current cargo load.")
    doc.add_bullet("Manual Fare Override & Approval Gate", "Cryptographically audited regulatory workflow allowing authorized managers to approve special freight quotes or volume discounts.")
    doc.add_bullet("KYC & Document Verification Pipeline", "Comprehensive compliance review suite for approving rider National Identity Numbers (NIN), driver licenses, and vehicle inspection certificates.")
    doc.add_bullet("Financial Reconciliation & Dual-Ledger Audit", "Real-time reconciliation of client receipts, escrow balances, driver payout batches, and platform net revenue.")

    doc.add_h3("Component 3: Delivery Partner (Driver) Dashboard")
    doc.add_p(
        "Engineered specifically for gig-economy motorcycle and tricycle couriers operating under intense outdoor glare and wearing riding gloves."
    )
    doc.add_bullet("High-Contrast Sunlight Mode", "One-tap toggle converting UI to ultra-bold monochrome and high-visibility amber/yellow accents, eliminating outdoor screen washout.")
    doc.add_bullet("Gloved Ergonomic Touch Architecture", "Minimum 44px–56px interactive touch targets and persistent bottom thumb-navigation bar preventing accidental misclicks.")
    doc.add_bullet("45-Second Order Broadcast Radar", "Audio-visual incoming job broadcast displaying pickup landmark, delivery LGA, total distance (km), cargo weight, and guaranteed driver earning.")
    doc.add_bullet("Visual Delivery Milestone Stepper", "Step-by-step dispatch workflow: Accept Job → En Route to Pickup → Confirm Cargo Arrival → Depart in Transit → Arrive at Destination → Enter Recipient OTP.")
    doc.add_bullet("Instant Earnings Transparency Wallet", "Live partner ledger showing daily completed deliveries, gross earnings, 65% net share, tips, and daily automated bank payout status.")
    doc.add_bullet("Shift Availability Management", "One-click toggle between Available, Paused (fuel/meal break), and Offline states.")

    doc.add_h3("Component 4: Live GPS Telemetry & Telematics Engine")
    doc.add_p(
        "A proprietary telematics streaming pipeline that captures high-frequency location data without draining smartphone batteries or saturating server resources."
    )
    doc.add_bullet("Adaptive Frequency Telemetry Beacons", "Transmits coordinates every 10 seconds during transit; throttles to 60 seconds when stationary to conserve rider mobile data and battery.")
    doc.add_bullet("Compass Heading & Heading Vectoring", "Normalizes smartphone gyroscope data into true cardinal direction (0°–360°), rotating vehicle map markers in real-time.")
    doc.add_bullet("Redis High-Throughput Ingestion Queue", "Absorbs thousands of concurrent telemetry packets per second, computing route breadcrumbs before persisting clean summaries to MySQL.")

    doc.add_h3("Component 5: Cryptographic Proof of Delivery (POD) Engine")
    doc.add_bullet("Dual-Handshake 6-Digit OTP", "System auto-generates a secure numeric OTP sent exclusively to the recipient upon dispatch. The rider cannot finalize delivery without entering this code.")
    doc.add_bullet("Tamper-Proof Audit Certificate", "Every completed delivery generates a permanent digital POD logging exact timestamp, GPS drop-off coordinates, receiver identity, and rider ID.")
    doc.add_bullet("Digital Commercial Invoicing", "Instant generation of downloadable, printable corporate invoices formatted with itemized fare breakdown for commercial tax filing.")

    doc.add_h3("Component 6: Algorithmic Smart Fare & Pricing Calculator")
    doc.add_p(
        "TIL uses a transparent, deterministic multi-parameter pricing model that balances merchant affordability with guaranteed partner profitability."
    )
    doc.add_callout(
        title="Mathematical Fare Formula",
        text="Total Fare = BaseFare + max(0, Distance_km - IncludedDistance) * PerKmRate + max(0, Weight_kg - IncludedWeight) * PerKgRate + FragileSurcharge + PerishableSurcharge + SurgeMultiplier\n\n• Base Fare: NGN 500 – NGN 750 (includes first 5 km and first 3 kg)\n• Distance Rate: NGN 100/km (standard), NGN 150/km for distance beyond 15 km threshold\n• Weight Rate: NGN 80/kg above included 3 kg\n• Fragile Cargo Insurance: +NGN 350 | Perishable Priority: +NGN 400\n• Rain / Peak Rush-Hour Multiplier: 1.15x – 1.35x"
    )

    doc.add_h3("Component 7: Multi-Zone Geofencing & Physical Hub Network")
    doc.add_p(
        "TIL divides the Kano metropolitan area into six high-density operational zones bounded by precise GPS polygons and anchored by 8 physical micro-warehousing depots."
    )
    doc.add_table(
        headers=["Operational Zone", "Primary Trade Focus & Key Markets", "Active Fleet Units", "Average Metro ETA"],
        rows=[
            ["Fagge Zone", "West Africa's primary textile capital (Kantin Kwari, Sabon Gari, France Road)", "40+ Bikes & Tricycles", "15 – 25 mins"],
            ["Dala Zone", "Historic high-density wholesale & retail (Dala Hill, Gwammaja, Koki Market)", "24 Motorbikes", "20 – 35 mins"],
            ["Gwale Zone", "Academic institutions, electronics & residential quarters (BUK, Kabuga, Goron Dutse)", "28 Bikes & Tricycles", "20 – 35 mins"],
            ["Tarauni Zone", "Corporate offices, pharmaceutical distributors & upscale residential (Maiduguri Rd)", "22 Motorbikes", "15 – 30 mins"],
            ["Nasarawa Zone", "State ministries, commercial banks, Bompai industrial layout & GRA", "30 Bikes & Vans", "15 – 25 mins"],
            ["Kumbotso Zone", "Heavy manufacturing belt, agricultural processors, Challawa Industrial corridor", "18 Heavy Tricycles & Vans", "25 – 40 mins"]
        ],
        col_widths=[2000, 4160, 1800, 1400]
    )

    doc.add_h3("Component 8: Driver Onboarding, KYC & Anti-Fraud Security")
    doc.add_p(
        "A rigorous 4-step compliance gate: (1) Identity verification via National Identity Number (NIN) / Bank Verification Number (BVN); (2) Vehicle inspection and insurance validation; (3) Verified community business guarantor affidavit; (4) Mandatory in-person orientation covering app usage, defensive riding, and customer ethics."
    )

    doc.add_page_break()

    # =========================================================================
    # SECTION 3: OPERATIONAL MODEL & KANO GROUND TOPOLOGY
    # =========================================================================
    doc.add_h1("3. Operational Model & Ground Fleet Execution")
    doc.add_p(
        "Unlike Western point-to-point courier apps that suffer from low vehicle density and excessive deadhead travel miles, Tweak Insight Logistics implements a hybrid Hub-and-Spoke and Point-to-Point dynamic dispatch model optimized for high-density commercial markets."
    )

    doc.add_h2("3.1 Tiered Commercial Fleet Composition")
    doc.add_table(
        headers=["Fleet Category", "Payload Capacity", "Ideal Cargo Use-Case", "Fleet Economics"],
        rows=[
            ["Class A: Express Motorbikes", "0 – 15 kg (Rear lockbox)", "Urgent documents, legal contracts, pharmaceuticals, small electronics, boutique apparel.", "Lowest fuel consumption (3.2L/100km), maximum traffic agility."],
            ["Class B: Cargo Tricycles (Keke)", "15 – 80 kg (Reinforced cargo bed)", "Wholesale textile bales from Kantin Kwari, bulk packaged food cartons, restaurant supplies.", "Optimal capacity-to-cost ratio; dominant mover in crowded market alleys."],
            ["Class C: Light Cargo Vans", "80 – 600 kg (Covered van)", "Multi-carton distributor transfers, inter-market bulk staging, regional freight consolidation.", "Anchor line-haul freight between peripheral hubs and regional export corridors."]
        ],
        col_widths=[2200, 1800, 3160, 2200]
    )

    doc.add_h2("3.2 8-Depot Physical Micro-Hub Infrastructure")
    doc.add_p(
        "TIL operates eight strategically positioned physical depots across metropolitan Kano. These micro-hubs serve four vital operational functions:"
    )
    doc.add_bullet("Midday Battery Swapping & Recharging", "Supports our pilot fleet of electric cargo motorbikes and tricycles, lowering operating costs by 42%.")
    doc.add_bullet("Cross-Docking & Parcel Consolidation", "Allows couriers to drop multiple parcels heading to neighboring LGAs for batched line-haul transfers, cutting fuel miles by 31%.")
    doc.add_bullet("Driver Rest & Training Centers", "Equipped with water, prayer areas, and device charging stations, maintaining high driver satisfaction and retention.")
    doc.add_bullet("Short-Term Secure Depository", "Allows merchants to stage outbound deliveries overnight for guaranteed 7:30 AM next-day morning delivery across Kano.")

    doc.add_page_break()

    # =========================================================================
    # SECTION 4: UNIT ECONOMICS & MULTI-STREAM REVENUE ARCHITECTURE
    # =========================================================================
    doc.add_h1("4. Unit Economics & Multi-Stream Revenue Architecture")
    doc.add_p(
        "Tweak Insight Logistics exhibits world-class software-enabled unit economics characterized by a high take rate, rapid cash conversion, and negative working capital cycles."
    )

    doc.add_h2("4.1 Granular Unit Economics per Delivery (Blended Metro Average)")
    doc.add_table(
        headers=["Financial Line Item", "Amount (NGN)", "Percentage of Fare", "Operational Notes"],
        rows=[
            ["Gross Customer Delivery Fare (AOV)", "NGN 2,250.00", "100.0%", "Blended average across Express, Standard, and Heavy Wholesale trips."],
            ["Delivery Partner Share (Driver Payout)", "NGN 1,462.50", "65.0%", "Paid to driver upon successful OTP delivery verification."],
            ["Gross Platform Revenue (TIL Take Rate)", "NGN 787.50", "35.0%", "Contractual platform gross commission."],
            ["Payment Gateway Processing Fee", "NGN 33.75", "1.5%", "Paystack debit card / USSD fee (capped)."],
            ["SMS & WhatsApp OTP Notifications", "NGN 18.00", "0.8%", "Carrier SMS + WhatsApp Cloud API verification message."],
            ["Cloud Compute, Database & Redis Cache", "NGN 16.50", "0.7%", "Prorated AWS/Linode micro-service compute per transaction."],
            ["Geospatial Vector Map Tiles & API", "NGN 8.75", "0.4%", "OpenStreetMap routing tile queries."],
            ["Driver Background Check Amortization", "NGN 8.00", "0.4%", "Amortized NIN/BVN compliance onboarding cost over 250 trips."],
            ["Total Direct Variable Costs (COGS)", "NGN 85.00", "3.8%", "Total variable fulfillment cost per transaction."],
            ["Net Contribution Margin per Delivery", "NGN 702.50", "31.2%", "Pure net margin after all variable fulfillment expenses!"]
        ],
        col_widths=[2600, 1600, 1760, 3400],
        align_right_cols={1, 2}
    )

    doc.add_h2("4.2 Diversified Multi-Stream Monetization Model")
    doc.add_p(
        "Rather than relying solely on transaction dispatch commissions, TIL monetizes seven complementary revenue streams across its commercial ecosystem:"
    )

    doc.add_bullet("Stream 1: On-Demand Dispatch Commission (35% Take Rate)", "Core revenue from every peer-to-peer, retail, and commercial dispatch transaction initiated across the web and mobile platform.")
    doc.add_bullet("Stream 2: Enterprise B2B SaaS Subscriptions", "Tiered monthly software-as-a-service packages for high-volume wholesale distributors (Kantin Kwari fabric houses, pharmaceutical wholesalers): Starter (NGN 35,000/mo), Growth (NGN 75,000/mo), and Enterprise Corporate (NGN 150,000/mo) providing bulk CSV dispatch, multi-clerk logins, priority driver allocation, and dedicated account managers.")
    doc.add_bullet("Stream 3: Physical Micro-Hub Storage & Cross-Docking Fees", "Short-term secure parcel holding fees (NGN 300–NGN 1,000 per package/night) for merchants staging regional freight bound for Katsina, Kaduna, or Niger Republic.")
    doc.add_bullet("Stream 4: High-Value Cargo Protection Surcharge", "Optional transit insurance for high-value electronics and luxury fabric bales, charged at 1.5% of declared item value with an 85% underwriting gross margin.")
    doc.add_bullet("Stream 5: Cash-on-Delivery (COD) Financial Remittance", "2.0% processing fee for collecting and digitally settling physical cash payments on behalf of retail merchants.")
    doc.add_bullet("Stream 6: In-App Merchant Advertising & Sponsored Zones", "Featured placement on the client portal home screen for top restaurants, fashion brands, and FMCG wholesalers (NGN 50,000–NGN 200,000/month).")
    doc.add_bullet("Stream 7: Electric Fleet Asset Financing & Equipment Margins", "Financing margin on electric motorcycles and branded delivery lockboxes leased to top-performing drivers.")

    doc.add_page_break()

    # =========================================================================
    # SECTION 5: 5-YEAR COMPREHENSIVE FINANCIAL PROJECTIONS
    # =========================================================================
    doc.add_h1("5. Comprehensive 5-Year Financial Operating Model (Years 1 – 5)")
    doc.add_p(
        "The following multi-year financial projections reflect a rigorous, bottom-up operational model based on verified unit economics, empirical market size data from Kano State, and structured regional expansion across Northern Nigeria and the Sahelian corridor."
    )

    doc.add_h2("5.1 Master Growth & Operational Drivers")
    doc.add_table(
        headers=["Operational Metric", "Year 1 (Kano Core)", "Year 2 (Kano + Zaria)", "Year 3 (Reg. Corridors)", "Year 4 (National B2B)", "Year 5 (Cross-Border)"],
        rows=[
            ["Active Operating Cities / Hubs", "1 Metro / 8 Hubs", "2 Cities / 16 Hubs", "4 Cities / 32 Hubs", "8 Cities / 55 Hubs", "12 Cities / 80 Hubs"],
            ["Average Daily Completed Deliveries", "750 / day", "2,500 / day", "6,500 / day", "14,000 / day", "28,000 / day"],
            ["Annual Completed Deliveries", "273,750", "912,500", "2,372,500", "5,110,000", "10,220,000"],
            ["Blended Average Order Value (AOV)", "NGN 2,100", "NGN 2,400", "NGN 2,750", "NGN 3,100", "NGN 3,500"],
            ["Gross Delivery Value (GDV / GMV)", "NGN 574,875,000", "NGN 2,190,000,000", "NGN 6,524,375,000", "NGN 15,841,000,000", "NGN 35,770,000,000"],
            ["USD Equivalent GDV (@ 1,500 NGN/$)", "$383,250 USD", "$1,460,000 USD", "$4,349,583 USD", "$10,560,667 USD", "$23,846,667 USD"],
            ["Active Registered Fleet Size", "120 Couriers", "380 Couriers", "950 Couriers", "2,100 Couriers", "4,200 Couriers"],
            ["Active Commercial B2B Merchants", "350 Merchants", "1,200 Merchants", "3,400 Merchants", "7,800 Merchants", "16,500 Merchants"]
        ],
        col_widths=[2360, 1400, 1400, 1400, 1400, 1400],
        align_right_cols={1, 2, 3, 4, 5}
    )

    doc.add_h2("5.2 5-Year Revenue Stream Matrix (NGN in Millions)")
    doc.add_table(
        headers=["Revenue Source", "Year 1", "Year 2", "Year 3", "Year 4", "Year 5"],
        rows=[
            ["Core Dispatch Take Rate (35%)", "NGN 201.2M", "NGN 766.5M", "NGN 2,283.5M", "NGN 5,544.4M", "NGN 12,519.5M"],
            ["Enterprise B2B SaaS Subscriptions", "NGN 14.7M", "NGN 64.8M", "NGN 224.4M", "NGN 561.6M", "NGN 1,485.0M"],
            ["Micro-Hub Storage & Cross-Docking", "NGN 4.5M", "NGN 22.8M", "NGN 85.4M", "NGN 245.3M", "NGN 613.2M"],
            ["High-Value Cargo Insurance Margin", "NGN 3.8M", "NGN 18.4M", "NGN 65.2M", "NGN 190.1M", "NGN 465.0M"],
            ["Cash-on-Delivery (COD) Remittance", "NGN 2.9M", "NGN 14.5M", "NGN 52.2M", "NGN 158.4M", "NGN 393.5M"],
            ["In-App Merchant Advertising", "NGN 1.4M", "NGN 8.0M", "NGN 29.3M", "NGN 120.2M", "NGN 273.8M"],
            ["TOTAL NET PLATFORM REVENUE", "NGN 228.5M", "NGN 895.0M", "NGN 2,740.0M", "NGN 6,820.0M", "NGN 15,750.0M"],
            ["Revenue in USD (@ 1,500 NGN/$)", "$152,333 USD", "$596,667 USD", "$1,826,667 USD", "$4,546,667 USD", "$10,500,000 USD"]
        ],
        col_widths=[2360, 1400, 1400, 1400, 1400, 1400],
        align_right_cols={1, 2, 3, 4, 5}
    )

    doc.add_h2("5.3 5-Year Comprehensive Income Statement (P&L)")
    doc.add_table(
        headers=["Financial Line Item", "Year 1", "Year 2", "Year 3", "Year 4", "Year 5"],
        rows=[
            ["Gross Delivery Value (GMV)", "NGN 574.9M", "NGN 2,190.0M", "NGN 6,524.4M", "NGN 15,841.0M", "NGN 35,770.0M"],
            ["Driver Payouts (65% share)", "(NGN 373.7M)", "(NGN 1,423.5M)", "(NGN 4,240.8M)", "(NGN 10,296.7M)", "(NGN 23,250.5M)"],
            ["Net Platform Revenue", "NGN 228.5M", "NGN 895.0M", "NGN 2,740.0M", "NGN 6,820.0M", "NGN 15,750.0M"],
            ["Cost of Goods Sold (COGS)", "(NGN 24.6M)", "(NGN 82.1M)", "(NGN 213.5M)", "(NGN 459.9M)", "(NGN 919.8M)"],
            ["GROSS PROFIT", "NGN 203.9M", "NGN 812.9M", "NGN 2,526.5M", "NGN 6,360.1M", "NGN 14,830.2M"],
            ["Gross Profit Margin (%)", "89.2%", "90.8%", "92.2%", "93.3%", "94.2%"],
            ["OPEX: Engineering & Software R&D", "(NGN 38.0M)", "(NGN 85.0M)", "(NGN 180.0M)", "(NGN 350.0M)", "(NGN 650.0M)"],
            ["OPEX: Ground Hub Operations & Depots", "(NGN 32.0M)", "(NGN 88.0M)", "(NGN 240.0M)", "(NGN 540.0M)", "(NGN 1,120.0M)"],
            ["OPEX: B2B Sales & Merchant Acquisition", "(NGN 28.0M)", "(NGN 76.0M)", "(NGN 195.0M)", "(NGN 420.0M)", "(NGN 880.0M)"],
            ["OPEX: Customer Support & Dispatch Control", "(NGN 18.0M)", "(NGN 54.0M)", "(NGN 145.0M)", "(NGN 320.0M)", "(NGN 640.0M)"],
            ["OPEX: Marketing, Branding & Merchant Promos", "(NGN 24.0M)", "(NGN 72.0M)", "(NGN 180.0M)", "(NGN 410.0M)", "(NGN 860.0M)"],
            ["OPEX: General, Legal, Regulatory & Admin", "(NGN 15.0M)", "(NGN 45.0M)", "(NGN 110.0M)", "(NGN 240.0M)", "(NGN 500.0M)"],
            ["TOTAL OPERATING EXPENSES (OPEX)", "(NGN 155.0M)", "(NGN 420.0M)", "(NGN 1,050.0M)", "(NGN 2,280.0M)", "(NGN 4,650.0M)"],
            ["EBITDA", "NGN 48.9M", "NGN 392.9M", "NGN 1,476.5M", "NGN 4,080.1M", "NGN 10,180.2M"],
            ["EBITDA Margin (%)", "21.4%", "43.9%", "53.9%", "59.8%", "64.6%"],
            ["Depreciation & Amortization", "(NGN 6.5M)", "(NGN 18.5M)", "(NGN 48.0M)", "(NGN 115.0M)", "(NGN 230.0M)"],
            ["EBIT (Operating Profit)", "NGN 42.4M", "NGN 374.4M", "NGN 1,428.5M", "NGN 3,965.1M", "NGN 9,950.2M"],
            ["Corporate Income Tax (30% Nigeria CIT)", "(NGN 12.7M)", "(NGN 112.3M)", "(NGN 428.5M)", "(NGN 1,189.5M)", "(NGN 2,985.1M)"],
            ["NET PROFIT AFTER TAX", "NGN 29.7M", "NGN 262.1M", "NGN 1,000.0M", "NGN 2,775.6M", "NGN 6,965.1M"],
            ["Net Profit Margin (%)", "13.0%", "29.3%", "36.5%", "40.7%", "44.2%"],
            ["Net Profit in USD (@ 1,500 NGN/$)", "$19,800 USD", "$174,733 USD", "$666,667 USD", "$1,850,400 USD", "$4,643,400 USD"]
        ],
        col_widths=[2360, 1400, 1400, 1400, 1400, 1400],
        align_right_cols={1, 2, 3, 4, 5}
    )

    doc.add_h2("5.4 5-Year Cash Flow & Balance Sheet Trajectory")
    doc.add_table(
        headers=["Cash Flow Category", "Year 1", "Year 2", "Year 3", "Year 4", "Year 5"],
        rows=[
            ["Cash Flow from Operations (CFO)", "NGN 41.2M", "NGN 315.4M", "NGN 1,120.5M", "NGN 3,085.2M", "NGN 7,650.4M"],
            ["Capital Expenditures (CAPEX - Hubs/Tech)", "(NGN 35.0M)", "(NGN 75.0M)", "(NGN 180.0M)", "(NGN 380.0M)", "(NGN 720.0M)"],
            ["Seed Investment Injection", "NGN 750.0M", "NGN 0.0M", "NGN 0.0M", "NGN 0.0M", "NGN 0.0M"],
            ["Net Annual Cash Generation", "NGN 756.2M", "NGN 240.4M", "NGN 940.5M", "NGN 2,705.2M", "NGN 6,930.4M"],
            ["CUMULATIVE CASH & CASH EQUIVALENTS", "NGN 756.2M", "NGN 996.6M", "NGN 1,937.1M", "NGN 4,642.3M", "NGN 11,572.7M"],
            ["Cash Balance in USD (@ 1,500 NGN/$)", "$504,133 USD", "$664,400 USD", "$1,291,400 USD", "$3,094,867 USD", "$7,715,133 USD"]
        ],
        col_widths=[2360, 1400, 1400, 1400, 1400, 1400],
        align_right_cols={1, 2, 3, 4, 5}
    )

    doc.add_h2("5.5 Key SaaS & Marketplace Efficiency Metrics")
    doc.add_table(
        headers=["Core Efficiency Benchmark", "Year 1", "Year 2", "Year 3", "Year 4", "Year 5"],
        rows=[
            ["Customer Acquisition Cost (CAC)", "NGN 3,400", "NGN 2,800", "NGN 2,100", "NGN 1,600", "NGN 1,200"],
            ["Merchant Lifetime Value (LTV)", "NGN 48,000", "NGN 72,000", "NGN 115,000", "NGN 168,000", "NGN 240,000"],
            ["LTV to CAC Ratio", "14.1x", "25.7x", "54.8x", "105.0x", "200.0x"],
            ["Monthly Merchant Retention Rate", "91.5%", "93.8%", "95.2%", "96.5%", "97.4%"],
            ["Driver Monthly Churn Rate", "4.2%", "3.5%", "2.8%", "2.1%", "1.8%"],
            ["Average Driver Monthly Earnings", "NGN 185,000", "NGN 225,000", "NGN 265,000", "NGN 310,000", "NGN 365,000"]
        ],
        col_widths=[2360, 1400, 1400, 1400, 1400, 1400],
        align_right_cols={1, 2, 3, 4, 5}
    )

    doc.add_page_break()

    # =========================================================================
    # SECTION 6: CAPITAL REQUIREMENT, USE OF FUNDS & VALUATION
    # =========================================================================
    doc.add_h1("6. Capital Requirement, Use of Funds & Valuation")

    doc.add_h2("6.1 The Seed / Series A Offering")
    doc.add_p(
        "Tweak Insight Logistics is raising NGN 750,000,000 (~$500,000 USD) in equity financing to accelerate market capture in metropolitan Kano, expand physical depot infrastructure, launch native mobile applications (iOS and Android), and pilot an electric two-wheel fleet transition."
    )

    doc.add_table(
        headers=["Strategic Capital Allocation", "Amount (NGN)", "Percentage (%)", "Key Strategic Deliverables Funded"],
        rows=[
            ["Fleet Assets & EV Financing Facility", "NGN 262,500,000", "35.0%", "Procurement of 150 branded cargo lockboxes, helmets, and revolving down-payment facility for electric cargo bikes."],
            ["Software Platform & AI Dispatch Engineering", "NGN 187,500,000", "25.0%", "Engineering native iOS/Android apps, predictive dispatch algorithms, automated batch routing, and enterprise ERP plugins."],
            ["Physical Micro-Hub Network Expansion", "NGN 150,000,000", "20.0%", "Leasing, solar electrification, security, and sorting automation for 16 micro-hubs across Kano and Kaduna."],
            ["Commercial B2B Sales & Merchant Growth", "NGN 90,000,000", "12.0%", "Direct merchant acquisition teams deployed in Kantin Kwari, Singer, and Dawanau; merchant co-marketing subsidies."],
            ["Regulatory Licenses & Working Capital Reserve", "NGN 60,000,000", "8.0%", "Federal postal/courier licensing (NIPOST courier permit), commercial insurance buffer, and 18-month regulatory runway."],
            ["TOTAL INVESTMENT USE OF PROCEEDS", "NGN 750,000,000", "100.0%", "Fully funded runway through cash-flow positivity and Year 2 market dominance."]
        ],
        col_widths=[2400, 1600, 1360, 4000],
        align_right_cols={1, 2}
    )

    doc.add_h2("6.2 Valuation Trajectory & Milestones")
    doc.add_table(
        headers=["Financing Stage", "Timeline", "Target Valuation", "Core Operational Milestone"],
        rows=[
            ["Current Round (Seed / Series A)", "Q4 2026", "NGN 3.0 Billion ($2.0M USD)", "Production platform live, 8 Kano hubs, 750+ daily trips, profitable unit economics."],
            ["Series B Expansion Round", "Q4 2028", "NGN 18.0 Billion ($12.0M USD)", "Kano saturation, Kaduna & Abuja corridors, 6,500+ daily trips, NGN 1.48B EBITDA."],
            ["Strategic Exit / Pre-IPO", "2030 – 2031", "NGN 75.0 Billion ($50.0M+ USD)", "Pan-West African cross-border logistics network, 28,000 daily trips, NGN 10.18B EBITDA."]
        ],
        col_widths=[2200, 1400, 2600, 3160]
    )

    doc.add_h2("6.3 Strategic Investor Exit Mechanisms")
    doc.add_bullet("Trade Sale to Pan-African E-Commerce / Logistics Giants", "Strategic acquisition by continental operators seeking instant, dominant Northern Nigerian and Sahelian distribution infrastructure (e.g. Jumia Logistics, Kobo360, Lori Systems, DHL Supply Chain Africa).")
    doc.add_bullet("Private Equity Secondary Buyout", "Sale of investor equity to regional infrastructure private equity funds seeking cash-flow generative logistics assets with >50% EBITDA margins.")
    doc.add_bullet("Public Listing on Nigerian Exchange (NGX)", "IPO on the Growth Board of the Nigerian Exchange Group, capitalizing on national demand for technology and transport infrastructure equities.")

    doc.add_page_break()

    # =========================================================================
    # SECTION 7: COMPETITIVE MOAT & RISK MITIGATION
    # =========================================================================
    doc.add_h1("7. Competitive Landscape & Risk Mitigation")

    doc.add_h2("7.1 Competitive Landscape Matrix")
    doc.add_table(
        headers=["Operational Feature", "Tweak Insight Logistics (TIL)", "Informal Roadside Couriers", "Legacy Courier Firms (DHL/FedEx)", "Generic Ride-Hailing Apps"],
        rows=[
            ["Sub-Second Live Telemetry", "YES — Full Leaflet/OSM vector map", "NO — Zero tracking capability", "PARTIAL — Checkpoint scans only", "YES — Ride passenger focus"],
            ["Dual Cryptographic OTP POD", "YES — Zero cargo release without OTP", "NO — Verbal/paper signoff", "NO — Handwritten signature only", "NO — Inconsistent delivery POD"],
            ["Outdoor Sunlight Mode", "YES — High-contrast screen filter", "N/A — No software app", "NO — Basic scanner interface", "NO — Standard consumer UI"],
            ["65% Guaranteed Driver Split", "YES — Automated daily settlement", "NO — Unpredictable broker take", "NO — Low fixed-wage drivers", "NO — Opaque commission cuts"],
            ["Physical Market Micro-Hubs", "YES — 8 depots in Kano markets", "NO — Stand under roadside trees", "NO — 1 central airport depot", "NO — Zero physical depots"],
            ["Wholesale B2B Cargo Handling", "YES — Multi-tier (Bikes, Trikes, Vans)", "PARTIAL — Unregulated overload", "NO — Expensive document focus", "NO — Small passenger trunk only"],
            ["Kano Market Density & Moat", "DEEP — Embedded in Kantin Kwari", "LOCAL — Fragmented individuals", "VERY LOW — Corporate bank focus", "VERY LOW — Centered in Lagos/Abuja"]
        ],
        col_widths=[2360, 2000, 1600, 1700, 1700]
    )

    doc.add_h2("7.2 Risk Management & Mitigation Matrix")
    doc.add_table(
        headers=["Risk Factor", "Severity", "Strategic Mitigation Protocol Implemented by TIL"],
        rows=[
            ["Fuel Price Volatility & Inflation", "High", "Dynamic fuel-indexation algorithm in smart pricing engine; phased transition to electric cargo motorbikes and tricycles with solar-powered depot swap stations."],
            ["Cargo Loss, Pilferage & In-Transit Theft", "Medium", "Mandatory 6-digit OTP verification; rider NIN/BVN biometric screening and legal guarantor bonds; transit insurance covering 100% of declared value."],
            ["Driver Churn & Disloyalty", "Medium", "Market-leading 65% net earnings payout; daily instant liquidity; subsidized maintenance partnerships; driver lounges with free Wi-Fi and prayer halls."],
            ["Regulatory / LGA Licensing Interruption", "Low", "Formal partnership with Kano State Ministry of Transport (Kano Road Traffic Agency - KAROTA); full compliance with NIPOST postal regulatory frameworks."],
            ["Telecommunications Network Blackouts", "Medium", "Local storage offline dispatch caching in PWA; SMS-fallback dispatch triggers ensuring job progress persists during 3G/4G connectivity drops."]
        ],
        col_widths=[2200, 1160, 6000]
    )

    doc.add_h2("7.3 Corporate Governance & ESG Impact")
    doc.add_bullet("Decent Work & Economic Empowerment (SDG 8)", "Transforming informal, exploited roadside youths into formalized, financially included logistics entrepreneurs earning 2.5x the Nigerian national minimum wage.")
    doc.add_bullet("Climate Action & Sustainable Logistics (SDG 13)", "Pioneering the commercial transition to clean electric two-wheelers and tricycles, displacing 1,400 metric tons of CO2 emissions annually by Year 3.")
    doc.add_bullet("Empowering Women & Micro-SMEs (SDG 5)", "Over 45% of retail merchants registered on TIL are female-led micro-enterprises operating in home-based catering, fashion design, and cosmetics in Northern Nigeria.")

    # Save document
    doc.save()
    print("=" * 70)
    print("INVESTOR MEMORANDUM & FINANCIAL MODEL DOCX COMPLETED SUCCESSFULLY!")
    print(f"Target File: {output_filename}")
    print("=" * 70)

if __name__ == '__main__':
    generate_full_memorandum()
