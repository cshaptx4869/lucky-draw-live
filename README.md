# 幸运抽奖直播

## 实现原理

通过 websocket 服务，将主控端的操作同步到被控端，实现类似直播效果。

## 启动

服务端 service 目录。默认监听 3000 端口。参加者名单和奖项在 config.php 中配置。

```bash
cd service
composer install
# 启动 websocket 服务
php app.php
```

![](https://github.com/user-attachments/assets/4e965890-1359-4773-82ba-7ae709f1aec7)

客户端 client 目录。默认连接本地 3000 端口。

- 主控 master 页面（仅能开一个）

![](https://github.com/user-attachments/assets/768f4d8d-bc6a-41ea-898b-2db176fae195)

- 被控 slave 页面（可开多个）

![](https://github.com/user-attachments/assets/2a87785e-b16e-454d-ba2e-cd130fc81eb9)

- 中奖名单

![](https://github.com/user-attachments/assets/3335f097-35d2-4b2f-b5c3-48522e87f4c1)

## 抽奖流程

1. 选择当次要抽奖的奖项
2. 点击『开始』按钮，进入抽奖状态
3. 点击『停！』按钮，生成抽奖结果
4. 点击任意奖项按钮，可以回到闲置状态，已中奖的用户标记为黄色，不会二次命中

PS：滚动鼠标滚轮，可以放大或缩小球体

