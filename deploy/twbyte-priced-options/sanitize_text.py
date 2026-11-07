"""Customer-facing wording cleanup (t26u). Applied to every product/category
description by build_payload.py AND used for the one-off DB update, so a re-import
cannot bring internal source/verification wording back."""
import re
RULES=[
 ('（与源站一致）',''),
 ('，与源站一致。','。'),
 ('（源站列表价；本站按月付展示，源站计费周期待核实）','（计费周期暂未开放）'),
 ('**可选配置（源站配置器已核对；本站暂未开放选择，价格为每月加价）**','**可选配置（每月加价）**'),
 ('**可选配置与附加价格：待核实**（源站配置器尚未逐项核对）','**可选配置与附加价格**：暂未开放'),
 ('**基础配置（源站列表原文）**','**基础配置**'),
 ('**基础配置（源站列表原文）：**','**基础配置**：'),
 ('是源站列出的可选区间','是可选区间'),
 ('源站列表价，周期待核实','暂未开放'),
 ('仅月付（已核实）','仅月付'),
 ('其他周期未取得','其他周期暂未开放'),
 ('：待核实','：暂未公布'),
 ('：**待核实','：**暂未公布'),
 ('**待核实','**暂未公布'),
 ('| 待核实 |','| 暂未公布 |'),
]
BAD=re.compile(r'源站|原站|myctgs|核对|核实|配置器|未取得|已核对|pid\s*\d',re.I)
def clean(s):
    if not s: return s
    for a,b in RULES: s=s.replace(a,b)
    # snapshot/backup billing is not confirmed -> not selectable, no price shown
    s=re.sub(r'- 快照：[^\n]*\n- 备份：[^\n]*','- 快照和备份：暂不可选，不计费',s)
    # Windows has no confirmed price -> not selectable
    s=s.replace('Debian、Windows、Arch','Debian、Arch')
    s=re.sub(r'(操作系统：[^\n]*Rocky Linux)(?!（)',r'\1（Windows 暂不可选）',s)
    return s
def leftovers(s): return BAD.findall(s or '')
