#!/usr/bin/env python3
"""Build the verified HK7 priced-option catalog and idempotent SQL.

Source of truth is the sanitized handoff (detailed-findings.json hk_cloud and
hk_cloud_common_addons). Prices are USD cents. Products without a verified
configurator are listed and are not given options.
"""

from __future__ import annotations

import json
from decimal import Decimal
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
JSON_PATH = ROOT / "src/modules/Product/resources/hk7-priced-options.json"
DEPLOY = Path(__file__).resolve().parent


def usd(amount) -> int:
    return int((Decimal(str(amount)) * 100).quantize(Decimal("1")))


def value(code, label, monthly, setup=0, default=False, sort=10):
    return {
        "code": code,
        "label": label,
        "monthly_cents": usd(monthly),
        "setup_cents": usd(setup),
        "is_default": default,
        "active": True,
        "stock_control": False,
        "quantity_in_stock": 0,
        "sort_order": sort,
    }


def group(code, label, sort, values):
    if sum(1 for item in values if item["is_default"]) != 1:
        raise SystemExit(f"{code} must have exactly one default")
    return {
        "code": code,
        "label": label,
        "required": True,
        "min_count": 1,
        "max_count": 1,
        "currency": "USD",
        "periods": ["1M"],
        "verified": True,
        "active": True,
        "sort_order": sort,
        "values": values,
    }


def tiers(prefix, rows):
    """rows: (code, label, monthly, setup, default)."""
    return [
        value(code, label, monthly, setup, default, (index + 1) * 10)
        for index, (code, label, monthly, setup, default) in enumerate(rows)
    ]


def bandwidth(rows):
    return group("bandwidth", "带宽", 10, tiers("bw", rows))


def ip_range(start, end, included):
    rows = []
    for number in range(start, end + 1):
        extra = (number - included) * Decimal("4.30")
        label = f"{number} 个"
        if number == included:
            label += "（含在基础价格内）"
        rows.append((f"ip-{number}", label, extra, 0, number == included))
    return group("ip", "IP 数量", 20, tiers("ip", rows))


def choices(code, label, sort, rows):
    return group(code, label, sort, tiers(code, rows))


LINUX = [
    ("ubuntu", "Ubuntu", 0, 0, True),
    ("centos", "CentOS", 0, 0, False),
    ("debian", "Debian", 0, 0, False),
    ("arch", "Arch", 0, 0, False),
    ("fedora", "Fedora", 0, 0, False),
    ("almalinux", "AlmaLinux", 0, 0, False),
    ("rocky", "Rocky Linux", 0, 0, False),
]
# Windows is named in the OS list for some plans and excluded for pid 119/60,
# but the capture has no Windows surcharge. It is not offered at 0.
DDOS = [
    ("ddos-2g", "2G（免费）", 0, 0, True),
    ("ddos-5g", "5G", 60, 39, False),
    ("ddos-10g", "10G", 90, 39, False),
    ("ddos-40g", "40G", "699.30", 39, False),
    ("ddos-100g", "100G", 1200, 39, False),
]


def uncertain_snapshot_backup():
    """One disabled control. Do not emit two chargeable dropdowns."""
    return {
        "code": "snapshot_backup",
        "label": "快照和备份",
        "required": False,
        "min_count": 0,
        "max_count": 0,
        "currency": "USD",
        "periods": ["1M"],
        "verified": False,
        "active": True,
        "sort_order": 70,
        "values": [],
    }


def common_tail():
    return [
        uncertain_snapshot_backup(),
        choices("antiddos", "AntiDDoS", 90, DDOS),
        choices("os", "操作系统", 100, LINUX),
    ]


def product(slug, title, pid, base, groups, cfg):
    return {
        "slug": slug,
        "title": title,
        "source_pid": pid,
        "verified": True,
        "base_monthly_cents": usd(base),
        "currency": "USD",
        "periods": ["1M"],
        "source_cfg": cfg,
        "groups": groups,
    }


PRODUCTS = [
    product(
        "mc-hk-gia-cloud-basic",
        "HK-GIA-Cloud-Basic",
        119,
        69,
        [
            bandwidth([("gia-100m", "GIA 100Mbps（含在基础价格内）", 0, 0, True)]),
            ip_range(1, 1, 1),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-1", "1 vCPU（含在基础价格内）", 0, 0, True),
                ("vcpu-2", "2 vCPU", 30, 0, False),
                ("vcpu-4", "4 vCPU", 40, 0, False),
            ]),
            choices("memory", "内存", 40, [("ram-2g", "2 GB（含在基础价格内）", 0, 0, True)]),
            choices("os_disk", "系统盘", 50, [("sys-10g", "10 GB（含在基础价格内）", 0, 0, True)]),
            choices("data_disk", "数据盘", 60, [("data-none", "无", 0, 0, True)]),
            *common_tail(),
        ],
        "GIA100M,1IP; vCPU 1 incl,2 +30,4 +40; RAM2G; OS disk 10G; no data disk",
    ),
    product(
        "mc-hk-cia-hpc-amd",
        "HK-CIA-HPC-AMD",
        60,
        88,
        [
            bandwidth([
                ("cia-10m", "CIA 10Mbps（含在基础价格内）", 0, 0, True),
                ("cia-20m", "CIA 20Mbps", 70, 0, False),
                ("cia-40m", "CIA 40Mbps", "124.30", 0, False),
            ]),
            ip_range(1, 5, 1),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-4", "4 vCPU（AMD EPYC，含在基础价格内）", 0, 0, True),
                ("vcpu-8", "8 vCPU（AMD EPYC）", 15, 0, False),
                ("vcpu-16", "16 vCPU（AMD EPYC）", 32, 0, False),
                ("vcpu-32", "32 vCPU（AMD EPYC）", 200, 0, False),
            ]),
            choices("memory", "内存", 40, [
                ("ram-4g", "4 GB（含在基础价格内）", 0, 0, True),
                ("ram-8g", "8 GB", 15, 0, False),
                ("ram-16g", "16 GB", 43, 0, False),
                ("ram-32g", "32 GB", 100, 0, False),
                ("ram-64g", "64 GB", 215, 0, False),
            ]),
            choices("os_disk", "系统盘", 50, [
                ("sys-50g", "50 GB NVMe（含在基础价格内）", 0, 0, True),
                ("sys-90g", "90 GB NVMe", 15, 0, False),
            ]),
            choices("data_disk", "数据盘", 60, [
                ("data-none", "无", 0, 0, True),
                ("data-50g", "50 GB", 15, 0, False),
                ("data-100g", "100 GB", 30, 0, False),
                ("data-200g", "200 GB", 60, 0, False),
                ("data-500g", "500 GB", 200, 0, False),
            ]),
            *common_tail(),
        ],
        "CIA; BW 10M incl,20M +70,40M +124.30; IP1 incl,2..5 +4.30/step; vCPU 4 incl,8 +15,16 +32,32 +200 (AMD EPYC); RAM 4G,8G +15,16G +43,32G +100,64G +215; OS disk 50G Nvme,90G +15; data N/A,50G +15,100G +30,200G +60,500G +200",
    ),
    product(
        "mc-hk-gia-cloud-intel",
        "HK-GIA-Cloud-Intel",
        90,
        160,
        [
            bandwidth([
                ("gia-100m", "GIA 100Mbps（含在基础价格内）", 0, 0, True),
                ("gia-200m", "GIA 200Mbps", "124.30", 0, False),
                ("gia-500m", "GIA 500Mbps", "233.10", 0, False),
            ]),
            ip_range(2, 8, 2),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-8", "8 vCPU（Platinum，含在基础价格内）", 0, 0, True),
                ("vcpu-16", "16 vCPU（Platinum）", 43, 0, False),
                ("vcpu-32", "32 vCPU（Platinum）", 100, 0, False),
            ]),
            choices("memory", "内存", 40, [
                ("ram-16g", "16 GB（含在基础价格内）", 0, 0, True),
                ("ram-32g", "32 GB", 100, 0, False),
                ("ram-64g", "64 GB", 215, 0, False),
            ]),
            choices("os_disk", "系统盘", 50, [
                ("sys-100g", "100 GB（含在基础价格内）", 0, 0, True),
                ("sys-200g", "200 GB", 11, 0, False),
                ("sys-500g", "500 GB", 26, 0, False),
            ]),
            choices("data_disk", "数据盘", 60, [
                ("data-900g", "900 GB（含在基础价格内）", 0, 0, True),
                ("data-2tb", "2 TB", 30, 0, False),
                ("data-4tb", "4 TB", 84, 0, False),
            ]),
            *common_tail(),
        ],
        "GIA; BW 100M,200M +124.30,500M +233.10; IP 2..8; vCPU 8 Platinum,16 +43,32 +100; RAM16G,32G +100,64G +215; sys 100G,200G +11,500G +26; data 900G,2TB +30,4TB +84",
    ),
    product(
        "mc-hk-cia-cloud-intel",
        "HK-CIA-Cloud-Intel",
        91,
        75,
        [
            bandwidth([
                ("cia-10m", "CIA 10Mbps（含在基础价格内）", 0, 0, True),
                ("cia-20m", "CIA 20Mbps", 30, 0, False),
                ("cia-40m", "CIA 40Mbps", "124.30", 0, False),
            ]),
            ip_range(2, 8, 2),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-4", "4 vCPU（E5-2696v4，含在基础价格内）", 0, 0, True),
                ("vcpu-8", "8 vCPU（E5-2696v4）", 15, 0, False),
                ("vcpu-16", "16 vCPU（E5-2696v4）", 43, 0, False),
                ("vcpu-32", "32 vCPU（E5-2696v4）", 100, 0, False),
            ]),
            choices("memory", "内存", 40, [
                ("ram-4g", "4 GB（含在基础价格内）", 0, 0, True),
                ("ram-16g", "16 GB", 43, 0, False),
                ("ram-32g", "32 GB", 100, 0, False),
                ("ram-64g", "64 GB", 215, 0, False),
            ]),
            choices("os_disk", "系统盘", 50, [
                ("sys-60g", "60 GB（含在基础价格内）", 0, 0, True),
                ("sys-100g", "100 GB", 5, 0, False),
                ("sys-200g", "200 GB", 11, 0, False),
                ("sys-500g", "500 GB", 26, 0, False),
            ]),
            choices("data_disk", "数据盘", 60, [
                ("data-100g", "100 GB（含在基础价格内）", 0, 0, True),
                ("data-900g", "900 GB", 15, 0, False),
                ("data-2tb", "2 TB", 30, 0, False),
                ("data-4tb", "4 TB", 60, 0, False),
            ]),
            *common_tail(),
        ],
        "CIA; BW 10M,20M +30,40M +124.30; IP2..8; vCPU 4 E5-2696v4,8 +15,16 +43,32 +100; RAM4G,16G +43,32G +100,64G +215; sys 60G,100G +5,200G +11,500G +26; data 100G,900G +15,2TB +30,4TB +60",
    ),
    product(
        "mc-hk-gia-cloud-amd",
        "HK-GIA-Cloud-AMD",
        87,
        144,
        [
            bandwidth([
                ("gia-100m", "GIA 100Mbps（含在基础价格内）", 0, 0, True),
                ("gia-200m", "GIA 200Mbps", "124.30", 0, False),
                ("gia-500m", "GIA 500Mbps", "233.10", 0, False),
            ]),
            ip_range(2, 8, 2),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-8", "8 vCPU（AMD EPYC，含在基础价格内）", 0, 0, True),
                ("vcpu-16", "16 vCPU（AMD EPYC）", 43, 0, False),
                ("vcpu-32", "32 vCPU（AMD EPYC）", 100, 0, False),
            ]),
            choices("memory", "内存", 40, [
                ("ram-16g", "16 GB（含在基础价格内）", 0, 0, True),
                ("ram-32g", "32 GB", 100, 0, False),
                ("ram-64g", "64 GB", 215, 0, False),
            ]),
            choices("os_disk", "系统盘", 50, [
                ("sys-100g", "100 GB（含在基础价格内）", 0, 0, True),
                ("sys-200g", "200 GB", 11, 0, False),
                ("sys-500g", "500 GB", 26, 0, False),
            ]),
            choices("data_disk", "数据盘", 60, [
                ("data-900g", "900 GB（含在基础价格内）", 0, 0, True),
                ("data-2tb", "2 TB", 30, 0, False),
                ("data-4tb", "4 TB", 60, 0, False),
                ("data-8tb", "8 TB", 180, 0, False),
                ("data-16tb", "16 TB", 360, 0, False),
            ]),
            *common_tail(),
        ],
        "GIA; BW 100M,200M +124.30,500M +233.10; IP2..8; vCPU 8 EPYC,16 +43,32 +100; RAM16G,32G +100,64G +215; sys 100G,200G +11,500G +26; data 900G,2TB +30,4TB +60,8TB +180,16TB +360",
    ),
    product(
        "mc-hk-cdia-cloud-amd",
        "HK-CDIA-Cloud-AMD",
        88,
        144,
        [
            bandwidth([("cdia-50m", "CDIA 50Mbps（固定，含在基础价格内）", 0, 0, True)]),
            ip_range(2, 8, 2),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-8", "8 vCPU（AMD EPYC，含在基础价格内）", 0, 0, True),
                ("vcpu-16", "16 vCPU（AMD EPYC）", 22, 0, False),
                ("vcpu-32", "32 vCPU（AMD EPYC）", 100, 0, False),
            ]),
            choices("memory", "内存", 40, [
                ("ram-16g", "16 GB（含在基础价格内）", 0, 0, True),
                ("ram-32g", "32 GB", 100, 0, False),
                ("ram-64g", "64 GB", 215, 0, False),
            ]),
            choices("os_disk", "系统盘", 50, [
                ("sys-100g", "100 GB（含在基础价格内）", 0, 0, True),
                ("sys-200g", "200 GB", 11, 0, False),
                ("sys-500g", "500 GB", 26, 0, False),
            ]),
            choices("data_disk", "数据盘", 60, [
                ("data-900g", "900 GB（含在基础价格内）", 0, 0, True),
                ("data-2tb", "2 TB", 30, 0, False),
                ("data-4tb", "4 TB", 60, 0, False),
            ]),
            *common_tail(),
        ],
        "CDIA 50M fixed; IP2..8; vCPU 8 EPYC,16 +22,32 +100; RAM16G,32G +100,64G +215; sys 100G,200G +11,500G +26; data 900G,2TB +30,4TB +60",
    ),
    product(
        "mc-hk-cia-cloud-amd",
        "HK-CIA-Cloud-AMD",
        84,
        65,
        [
            bandwidth([
                ("cia-10m", "CIA 10Mbps（含在基础价格内）", 0, 0, True),
                ("cia-20m", "CIA 20Mbps", 30, 0, False),
                ("cia-40m", "CIA 40Mbps", "124.30", 0, False),
                ("cia-100m", "CIA 100Mbps", 999, 0, False),
            ]),
            ip_range(2, 8, 2),
            choices("vcpu", "vCPU", 30, [
                ("vcpu-4", "4 vCPU（AMD EPYC，含在基础价格内）", 0, 0, True),
                ("vcpu-8", "8 vCPU（AMD EPYC）", 15, 0, False),
                ("vcpu-16", "16 vCPU（AMD EPYC）", 43, 0, False),
                ("vcpu-32", "32 vCPU（AMD EPYC）", 100, 0, False),
                ("vcpu-64", "64 vCPU（AMD EPYC）", 445, 0, False),
            ]),
            choices("memory", "内存", 40, [
                ("ram-4g", "4 GB（含在基础价格内）", 0, 0, True),
                ("ram-16g", "16 GB", 43, 0, False),
                ("ram-32g", "32 GB", 100, 0, False),
                ("ram-64g", "64 GB", 215, 0, False),
            ]),
            choices("os_disk", "系统盘", 50, [
                ("sys-60g", "60 GB（含在基础价格内）", 0, 0, True),
                ("sys-100g", "100 GB", 15, 0, False),
            ]),
            choices("data_disk", "数据盘", 60, [
                ("data-100g", "100 GB（含在基础价格内）", 0, 0, True),
                ("data-900g", "900 GB", 15, 0, False),
                ("data-2tb", "2 TB", 30, 0, False),
                ("data-4tb", "4 TB", 60, 0, False),
            ]),
            *common_tail(),
        ],
        "CIA; BW 10M,20M +30,40M +124.30,100M +999; IP2..8; vCPU 4,8 +15,16 +43,32 +100,64 +445; RAM4G,16G +43,32G +100,64G +215; sys 60G,100G +15; data 100G,900G +15,2TB +30,4TB +60",
    ),
]

# The other imported mc-* products. No option rows and no invented prices.
UNVERIFIED_SLUGS = [
    "mc-jp-gia-cloud-basic",
    "mc-jp-cia-cloud-amd",
    "mc-jp-cia-hpc-amd",
    "mc-jp-sb-hpc-amd",
    "mc-jp-gia-cloud-amd",
    "mc-sg-gia-cloud-basic",
    "mc-sg-gia-cloud-amd",
    "mc-sg-cia-cloud-amd",
    "mc-sg-gia-cloud-intel",
    "mc-sg-cia-cloud-intel",
    "mc-kr-cia-cloud-8180m",
    "mc-kr-kt-cloud-8180m",
    "mc-th-hpc-amd",
    "mc-tw-hpc-amd",
    "mc-vn-cia-hpc-amd",
    "mc-hk-ds-01",
    "mc-hk-ds-02",
    "mc-hk-ds-03",
    "mc-hk-ds-04",
    "mc-hk-ds-05",
    "mc-hk-ds-06",
    "mc-hk-ds-07",
    "mc-hk-ds-08",
    "mc-hk-ds-09",
    "mc-hk-ds-10",
    "mc-hk-ds-11",
    "mc-hk-ds-12",
    "mc-hk-ds-13",
    "mc-hk-ds-14",
    "mc-jp-ds-01",
    "mc-jp-ds-02",
    "mc-jp-ds-03",
    "mc-jp-ds-04",
    "mc-jp-ds-05",
    "mc-sg-ds-01",
    "mc-sg-ds-02",
    "mc-sg-ds-03",
    "mc-kr-ds-01",
    "mc-vn-ds-01",
    "mc-th-ds-01",
]


def assign_ids(products):
    for product_index, item in enumerate(products, start=1):
        for group_index, item_group in enumerate(item["groups"], start=1):
            item_group["id"] = product_index * 1000 + group_index
            for value_index, item_value in enumerate(item_group["values"], start=1):
                item_value["id"] = item_group["id"] * 100 + value_index


def sql_literal(value):
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, int):
        return str(value)
    text = str(value).replace("\\", "\\\\").replace("'", "''")
    return "'" + text + "'"


def build_sql(products):
    slugs = [item["slug"] for item in products]
    slug_list = ", ".join(sql_literal(slug) for slug in slugs)
    lines = [
        "-- Idempotent HK7 priced-option import.",
        "-- Touches only product_option_group and product_option_value.",
        "-- Does not update product, product_payment, client_order, or invoice_item.",
        "START TRANSACTION;",
        "DELETE v FROM product_option_value v",
        "INNER JOIN product_option_group g ON g.id = v.group_id",
        "INNER JOIN product p ON p.id = g.product_id",
        f"WHERE p.slug IN ({slug_list});",
        "DELETE g FROM product_option_group g",
        "INNER JOIN product p ON p.id = g.product_id",
        f"WHERE p.slug IN ({slug_list});",
    ]
    for item in products:
        for item_group in item["groups"]:
            lines.append(
                "INSERT INTO product_option_group "
                "(product_id, code, label, required, min_count, max_count, currency, periods, verified, active, sort_order, created_at, updated_at) "
                "SELECT p.id, "
                + ", ".join(
                    [
                        sql_literal(item_group["code"]),
                        sql_literal(item_group["label"]),
                        sql_literal(item_group["required"]),
                        sql_literal(item_group["min_count"]),
                        sql_literal(item_group["max_count"]),
                        sql_literal(item_group["currency"]),
                        sql_literal(",".join(item_group["periods"])),
                        sql_literal(item_group["verified"]),
                        sql_literal(item_group["active"]),
                        sql_literal(item_group["sort_order"]),
                        "NOW()",
                        "NOW()",
                    ]
                )
                + f" FROM product p WHERE p.slug = {sql_literal(item['slug'])};"
            )
            for item_value in item_group["values"]:
                lines.append(
                    "INSERT INTO product_option_value "
                    "(group_id, code, label, monthly_price_cents, setup_price_cents, is_default, active, stock_control, quantity_in_stock, sort_order, created_at, updated_at) "
                    "SELECT g.id, "
                    + ", ".join(
                        [
                            sql_literal(item_value["code"]),
                            sql_literal(item_value["label"]),
                            sql_literal(item_value["monthly_cents"]),
                            sql_literal(item_value["setup_cents"]),
                            sql_literal(item_value["is_default"]),
                            sql_literal(item_value["active"]),
                            sql_literal(item_value["stock_control"]),
                            sql_literal(item_value["quantity_in_stock"]),
                            sql_literal(item_value["sort_order"]),
                            "NOW()",
                            "NOW()",
                        ]
                    )
                    + " FROM product_option_group g INNER JOIN product p ON p.id = g.product_id "
                    + f"WHERE p.slug = {sql_literal(item['slug'])} AND g.code = {sql_literal(item_group['code'])};"
                )
    lines.append("COMMIT;")
    lines.append(
        "-- Expect 7 products. A slug that does not exist inserts nothing; the PHP importer reports that."
    )
    return "\n".join(lines) + "\n"


def main():
    if len(UNVERIFIED_SLUGS) != 40:
        raise SystemExit(f"expected 40 unverified slugs, got {len(UNVERIFIED_SLUGS)}")
    assign_ids(PRODUCTS)
    document = {
        "currency": "USD",
        "periods": ["1M"],
        "source": "handoff detailed-findings hk_cloud + hk_cloud_common_addons, checked 2026-11-07",
        "note": "Only these seven products have a verified configurator. Option surcharges are monthly USD cents. AntiDDoS setup is separate and is not folded into the monthly price. Snapshot/backup and Windows are recorded as uncertain and are not priced.",
        "uncertain": [
            {
                "code": "snapshot_backup",
                "disabled": True,
                "evidence": "browser pid 119: 'Snapshots N/A/1 +14/2 +26; Backup same'. detailed-findings hk_cloud_common_addons repeats the same price string under snapshots and backup.",
                "reason": "不能确定快照和备份是一个合并选项还是两个分别计费的下拉。为避免把一次选择拆成两项收费，该项关闭且不计价。",
            },
            {
                "code": "os_windows",
                "disabled": True,
                "evidence": "os list names Windows except pid 119 and 60. No Windows surcharge is recorded.",
                "reason": "Windows 是否可选已有记录，但没有明确加价或明确的 0 元。不按免费系统提供。",
            },
        ],
        "products": PRODUCTS,
        "unverified_products": [
            {
                "slug": slug,
                "reason": "源站配置器未逐项核对，不创建选项，也不使用 0 元占位价。",
            }
            for slug in UNVERIFIED_SLUGS
        ],
        "unchanged_products": ["hk-1c1g", "hk-2c2g", "hk-4c4g"],
    }
    JSON_PATH.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (DEPLOY / "002_hk7_options.sql").write_text(build_sql(PRODUCTS), encoding="utf-8")
    print(f"products={len(PRODUCTS)} unverified={len(UNVERIFIED_SLUGS)} json={JSON_PATH}")


if __name__ == "__main__":
    main()
