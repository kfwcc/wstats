# 第三方组件与许可声明（Third-Party Notices）

本项目自身以 [MIT 许可证](LICENSE) 发布。运行时会用到下列第三方组件（部分已打包进
`public/assets/`、`public/geo/`、`data/`），它们的版权归各自作者所有，均采用**宽松许可证**，
允许商用与再分发，但**要求保留其版权与许可声明**。再次分发本项目（含打包后的发布包、Docker 镜像）时，
请一并保留本文件与其许可文本。

| 组件 | 许可证 | 版权 / 上游 |
|---|---|---|
| React、React DOM | MIT | Copyright (c) Facebook, Inc. and its affiliates · https://github.com/facebook/react |
| Semi Design（`@douyinfe/semi-ui`） | MIT | Copyright (c) 2021 DouyinFE · https://github.com/DouyinFE/semi-design |
| Apache ECharts / zrender | Apache-2.0 | Copyright The Apache Software Foundation · https://github.com/apache/echarts |
| lodash | MIT | Copyright JS Foundation and other contributors · https://github.com/lodash/lodash |
| tslib | 0BSD | Copyright (c) Microsoft Corporation · https://github.com/microsoft/tslib |
| ip2region（`data/ip2region.xdb`、`data/ip2region_v6.xdb`） | Apache-2.0 或 MIT（双许可，任选其一） | Copyright (c) 2015 Lionsoul · https://github.com/lionsoul2014/ip2region |

打包产物中已保留对应组件的许可声明（例如 `public/assets/index-*.js` 内的 React `@license`、
`public/assets/echarts-*.js` 内的 tslib 声明、`public/geo/*.json` 的地理边界数据）。各组件的完整许可文本以上游仓库的
`LICENSE` 文件为准。

## 需要注意的数据文件

- `public/geo/china.json`：省级行政区边界（含 `adcode`/`center`/`centroid` 字段，来源于阿里云 DataV.GeoAtlas 系列）。
  该数据属于**地图数据**，不属于代码许可范畴：用于对外公开的地图展示时，请遵守地图数据来源方的使用条款，
  并按自然资源部要求使用标准地图 / 标注审图号（如需对外提供公开地图服务，另行做地图合规确认）。
- `public/geo/world.json`：世界国界边界（OGC:CRS84），同样仅用于图表展示。
