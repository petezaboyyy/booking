# คู่มือเข้าดูฐานข้อมูลด้วย Adminer

Adminer คือหน้าเว็บสำหรับดูและจัดการฐานข้อมูลของระบบจองห้อง (MariaDB `now_booking`)
ดูตาราง ค้นหาข้อมูล และรันคำสั่ง SQL ได้ผ่าน browser

> **ความปลอดภัย:** Adminer เปิดไว้ที่ `127.0.0.1:8081` จึงเข้าได้จากเครื่อง VPS เท่านั้น
> ถ้าจะเข้าจากเครื่องอื่นต้องผ่าน SSH tunnel (ดูขั้นที่ 2)
> **ห้ามเปลี่ยนให้ไปเปิดที่ `0.0.0.0`** ไม่เช่นนั้นใครก็เข้าหน้าฐานข้อมูลได้

---

## ขั้นที่ 1: เปิด Adminer (ทำครั้งเดียว)

รันบนเครื่อง VPS ในโฟลเดอร์โปรเจกต์:

```bash
cd ~/Documents/booking
sudo docker compose up -d adminer
sudo docker compose ps adminer
```

ถ้าคอลัมน์ `STATUS` ขึ้น `Up` แปลว่าพร้อมใช้งาน
หลังจากนี้ Adminer จะเปิดเองทุกครั้งที่เครื่องรีสตาร์ต

---

## ขั้นที่ 2: เปิดหน้า Adminer

### แบบ ก: ใช้ browser บนเครื่อง VPS
เปิด **http://localhost:8081**

### แบบ ข: ใช้คอมพิวเตอร์ของตัวเอง (ผ่าน SSH tunnel)
1. เปิด Terminal (macOS/Linux) หรือ PowerShell (Windows) บนเครื่องของตัวเอง
2. รันคำสั่ง แล้วใส่รหัสผ่านของ user `vmroom-v2` บน VPS:
   ```bash
   ssh -L 8081:127.0.0.1:8081 vmroom-v2@10.209.121.30
   ```
3. **เปิดหน้าต่างนี้ค้างไว้** ระหว่างที่ใช้งาน ถ้าปิดหน้าต่าง การเชื่อมต่อจะหลุด
4. เปิด browser บนเครื่องตัวเองไปที่ **http://localhost:8081**

> ต้องอยู่ในเครือข่ายที่เข้าถึง `10.209.121.30` ได้ เช่นเครือข่ายภายในคณะ หรือผ่าน VPN มหาวิทยาลัย

---

## ขั้นที่ 3: เข้าสู่ระบบ Adminer

กรอกข้อมูลในหน้า login ดังนี้

| ช่อง | ค่าที่ต้องกรอก |
|---|---|
| **System** | `MySQL` |
| **Server** | `database` |
| **Username** | `booking` |
| **Password** | ค่า `MARIADB_PASSWORD` ใน `compose.yaml` |
| **Database** | `now_booking` |

กด **Login**

> ถ้าต้องการสิทธิ์เต็ม ใช้ Username `root` คู่กับรหัส `MARIADB_ROOT_PASSWORD` ใน `compose.yaml`
> ใช้เฉพาะเมื่อจำเป็นเท่านั้น

---

## ขั้นที่ 4: ดูข้อมูลในตาราง

1. แถบซ้ายแสดงรายชื่อตาราง กดชื่อตารางที่ต้องการ
2. กด **Select data** เพื่อดูข้อมูลในตาราง
3. กด **Show structure** เพื่อดูโครงสร้างคอลัมน์
4. ถ้าจะรันคำสั่งเอง กด **SQL command** ที่แถบซ้าย แล้วพิมพ์คำสั่ง เช่น
   ```sql
   SELECT id, username, name, status FROM app_user LIMIT 20;
   ```

### ตารางหลักในระบบ

| ตาราง | เก็บข้อมูล |
|---|---|
| `app_user` | ผู้ใช้ (`status` = 1 คือแอดมิน) |
| `app_user_session` | session ที่ login อยู่ |
| `app_rooms` / `app_rooms_meta` | ห้องและรายละเอียดห้อง |
| `app_reservation` / `app_reservation_data` | การจองห้อง |
| `app_reservation_occurrence` | วันเวลาของการจองแบบซ้ำ |
| `app_category` | หมวดหมู่ เช่นประเภทการจอง |
| `app_logs` | บันทึกการใช้งาน |
| `app_login_attempt` | ประวัติการพยายาม login |

> รูปห้อง**ไม่ได้**เก็บอยู่ในฐานข้อมูล แต่เก็บเป็นไฟล์ในโฟลเดอร์ `datas/booking/<id ห้อง>/`

---

## ข้อควรระวัง

- Adminer **แก้ไขและลบข้อมูลได้** ถ้าจะแค่ดู อย่ากดปุ่ม **Edit**, **Delete**, **Truncate** หรือ **Drop**
- ก่อนแก้ข้อมูลจริง ให้สำรองฐานข้อมูลก่อนเสมอ:
  ```bash
  cd ~/Documents/booking
  sudo docker compose exec -T database mariadb-dump -uroot -p<รหัส root> now_booking > db-backups/backup-$(date +%Y%m%d-%H%M).sql
  ```
- ใช้เสร็จแล้วกด **Logout** มุมขวาบน

---

## แก้ปัญหาเบื้องต้น

| อาการ | วิธีแก้ |
|---|---|
| เปิด `http://localhost:8081` ไม่ได้ | ตรวจว่า Adminer รันอยู่: `sudo docker compose ps adminer` ถ้าใช้ SSH tunnel ให้ตรวจว่าหน้าต่าง ssh ยังเปิดอยู่ |
| Login ไม่ได้ ขึ้นว่า `Access denied` | ตรวจ Username และ Password ให้ตรงกับ `compose.yaml` และช่อง Server ต้องเป็น `database` |
| ขึ้นว่า `No such host` / ต่อ server ไม่ได้ | ตรวจว่าฐานข้อมูลรันอยู่: `sudo docker compose ps database` |
| ต้องการปิด Adminer | `sudo docker compose stop adminer` |
