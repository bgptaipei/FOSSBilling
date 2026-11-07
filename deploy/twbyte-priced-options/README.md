# twbyte 可计价下拉配置

适用于 bgptaipei/FOSSBilling 分支 `0.8.9`（基线 `e594f8a5f91ea4cc905708f8dc1e42ccb752ed21`）上的部署。站点环境：PHP 8.3、Ubuntu 24.04、Huraga、默认币种 USD。本目录不包含数据库口令，也不会连接线上库。

## 行为

订购页对已核实的配置使用下拉框。合计由服务器按目录价计算，购物车、订单和发票使用同一套整数分计算。客户端提交的 `price`、`setup_price`、`rate` 会被丢掉。

- 快照和备份在源站记录里不能确定是一个选项还是两个分别计费的下拉（浏览器原文是 “Snapshots …; Backup same”，两边价目相同）。这一项关闭，不计价。
- Windows 只被点名是否出现，没有加价。不按 0 元提供。pid 119 和 60 仍然明确不提供 Windows。
- 周期加价进入订单单价，续费沿用下单时写入订单配置的快照，不重新读取后来改过的目录价。
- AntiDDoS 开通费是单独的发票行，不并进月租，续费发票不包含开通费。
- `mc-*` 产品保持 `stock_control = 1` 且库存为 0。可以预览价格，直接调用购物车或下单接口仍会因库存失败。
- 原 HK 套餐 `hk-1c1g`、`hk-2c2g`、`hk-4c4g` 没有选项行，价格、零元周期和 OpenNebula 开通都不改。
- 支付网关保持关闭。本改动不修改支付适配器。
- OpenNebula 插件仍只读取产品上的 `plugin_config`。选项快照只供人工履约和以后的桥接，不会改 VM 规格。

## 已核实与未核实

只有这 7 个产品有源站配置器明细（源站 pid 119、60、90、91、87、88、84）：

| slug | 源站 pid | 基础月费 USD | Windows |
| --- | --- | --- | --- |
| mc-hk-gia-cloud-basic | 119 | 69.00 | 源站明确不提供 |
| mc-hk-cia-hpc-amd | 60 | 88.00 | 源站明确不提供 |
| mc-hk-gia-cloud-intel | 90 | 160.00 | 名单中有，加价未记录，不可选 |
| mc-hk-cia-cloud-intel | 91 | 75.00 | 名单中有，加价未记录，不可选 |
| mc-hk-gia-cloud-amd | 87 | 144.00 | 名单中有，加价未记录，不可选 |
| mc-hk-cdia-cloud-amd | 88 | 144.00 | 名单中有，加价未记录，不可选 |
| mc-hk-cia-cloud-amd | 84 | 65.00 | 名单中有，加价未记录，不可选 |

其余 40 个 `mc-*` 产品没有配置器明细。导入脚本不会给它们建选项，也不会写 0 元占位。订购页显示“可选配置暂未开放”。以后把核对过的组写进 `hk7-priced-options.json` 的 `products` 再导入即可。`verified = 0` 的组会计价拒绝，不会按 0 元成交。

## 币种

新的价格、新购物车和新注册只使用 USD。CNY 币种行保留（汇率 6.7），已处理的历史交易保持原币种。客户 id 2 已是 USD，礼品余额数字不变，按美元读取。不要把余额按汇率折算，不要改写历史交易，不要启用支付网关。空的非美元购物车可以改到 USD，因为里面没有金额；已有商品的非美元购物车不改价，结算时拒绝并要求清空后重选。美元账户的余额可以支付美元发票。非美元余额不能拿去付美元发票。

IP 加价是每多 1 个 IP 4.30 美元，并且只存在源站写明的档位里：pid 119 只有 1 个；pid 60 是 1 到 5；其余 5 款是 2 到 8。没有把这个阶梯推广到未写明的产品。

## 部署顺序

1. 备份数据库，至少包括 `product`、`product_payment`、`client_order`、`invoice_item`。
2. 部署本分支代码。订购页和门户样式在 `src/themes/huraga/assets`，管理端样式在 `src/themes/admin_default/assets/css/twbyte-admin.css`，由主题直接引用，不需要重新构建前端资源。
3. 先执行 `001_schema.sql`。它只创建 `product_option_group` 和 `product_option_value`。
4. 再导入选项。两条路径二选一：
   - 推荐：`php import-priced-options.php --config=/var/www/fossbilling/config.php --data=hk7-priced-options.json dry`，确认输出的组数和值数后把 `dry` 换成 `apply`。缺 slug 会退出且不写入。
   - 或者执行 `002_hk7_options.sql`。它按 slug 删除并重建这 7 个产品的选项行。slug 不存在时对应插入为空，不会改产品价格。
5. 代码在表还不存在时会跳过选项并保持原价，所以先发代码、后跑 SQL 不会打断现有 3 个 HK 套餐。选项下拉要等 SQL 和导入完成才出现。

导入不更新 `product`、`product_payment`、订单或发票金额，也不打开支付网关。

## 回滚

1. 执行 `rollback.sql`。只删除上面两张新表。
2. 将代码退回上一版。已有订单的 `config` 里可能留有 `priced_option_snapshot`。旧代码忽略不认识的键，续费仍使用订单上已经保存的 `price`。
3. 不要用回滚脚本去改历史订单或发票。

## 校验

- 7 个产品的默认下拉合计等于上表月费，开通费为 0。
- HK-CIA-HPC-AMD 选择 CIA 20Mbps、3 个 IP、8 vCPU、8 GB、90 GB 系统盘、100 GB 数据盘、快照 1、备份 2、AntiDDoS 5G、Ubuntu：周期费用 341.60，开通费 39.00。
- 把别的产品的带宽选项 ID 提交到这个产品会被拒绝。
- 请求里的 `price=0.01` 不会改变合计。
- `mc-*` 直接调用 `cart/add_item` 仍然因为库存为 0 失败。
- 续费发票金额等于下单时的周期费用，不含 39 美元开通费；之后修改目录价不会改这张订单的续费快照。

已知、这次故意不改的问题：原 3 个 HK 套餐的 w/b/bia/tria 周期仍是启用且价格 0.00。
