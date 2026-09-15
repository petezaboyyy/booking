# Roomly - User UI Handoff Package
**ระบบจองห้องคณะสัตวแพทยศาสตร์ (Veterinary Medicine Room Reservation System)**

เอกสารฉบับนี้จัดทำขึ้นเพื่อส่งมอบชุดไฟล์ **User UI (ฝั่งผู้ใช้งานทั่วไป)** ให้แก่ทีมพัฒนาหลังบ้านและแอดมิน (Backend & Admin Team) โดยชุดไฟล์ในโฟลเดอร์นี้ประกอบด้วยหน้าจอและคอมโพเนนต์สำหรับฝั่งผู้ใช้ทั้งหมด 4 หน้าหลัก ไม่ปะปนกับโค้ดฝั่งแอดมิน

---

## 1. ขอบเขตงาน (Scope of User UI)

หน้าระบบฝั่ง User ประกอบด้วย **4 หน้าหลัก** ที่ออกแบบด้วยธีม Roomly (Modern, Responsive, Mobile-friendly):

| หน้าจอ | เส้นทาง (Route) | ไฟล์เทมเพลต | หน้าที่หลัก |
| :--- | :--- | :--- | :--- |
| **1. Dashboard** | `/` | `templates/index.html` | แสดงการ์ดสถิติ (รอตรวจสอบ, ส่งกลับแก้ไข, อนุมัติแล้ว), ปฏิทินจองห้อง, และปุ่มลัดจองด่วน |
| **2. ประวัติการจองห้อง** | `/my-bookings` | `templates/booking/my-bookings.html` | รายการประวัติการจองของตนเอง, สถานะการอนุมัติ, รายละเอียด และปุ่มยกเลิกการจอง |
| **3. จองห้อง** | `/booking` | `templates/booking/booking-recurring.html` | ฟอร์มจองห้องแบบ Single-surface, Combobox เลือกห้องพร้อมพิมพ์ค้นหา/ฟิลเตอร์แบบเรียลไทม์, ปฏิทินป็อปอัพตรวจสอบเวลาทับซ้อน, สรุปผลสด (Live summary) |
| **4. ดูห้องทั้งหมด** | `/rooms` | `templates/booking/catalog.html` | หน้ารวมห้องประชุม/ห้องเรียนทั้งหมด พร้อมรูปภาพ, ความจุผู้เข้าร่วม, สิ่งอำนวยความสะดวก และปุ่มเลือกจอง |
| *(แถม) เข้าสู่ระบบ* | `/login` | `templates/login.html` | หน้าล็อกอินปรับธีมคณะสัตวแพทยศาสตร์ (โลโก้ + สีประจำคณะ) |

---

## 2. โครงสร้างโฟลเดอร์ส่งมอบ (Directory Structure)

```text
ui-handoff/
├── README.md                           # คู่มือส่งมอบฉบับนี้
├── index.html                          # Single Page Entry Point & Script Loaders
├── css/
│   ├── dashboard.css                   # สไตล์ชีตสำหรับ Dashboard, สถิติ, และ Calendar
│   └── styles.css                      # สไตล์ชีตโครงสร้างหลัก
├── templates/
│   ├── index.html                      # [1/4] User Dashboard
│   ├── login.html                      # หน้าเข้าสู่ระบบ
│   └── booking/
│       ├── my-bookings.html            # [2/4] ประวัติการจองห้อง
│       ├── booking-recurring.html      # [3/4] ฟอร์มจองห้อง
│       ├── catalog.html                # [4/4] รายการห้องทั้งหมด
│       ├── calendar.html               # คอมโพเนนต์ปฏิทิน (Modal Calendar)
│       └── style.css                   # ระบบธีมและสไตล์หลักของ Roomly
├── modules/
│   └── booking/
│       └── admin.js                    # ฟังก์ชัน UI ฝั่ง User (Combobox Portal, Live Filter, Live Summary, Calendar Modal)
├── js/
│   └── main.js                         # Routing & Auth Initialization
├── language/
│   ├── th.json / th.php                # พจนานุกรมแปลภาษาไทย
│   └── en.json / en.php                # พจนานุกรมแปลภาษาอังกฤษ
└── images/
    └── logo.png                        # ตราสัญลักษณ์คณะสัตวแพทยศาสตร์
```

---

## 3. สัญญาณเชื่อมต่อ API (API Contracts Used by User UI)

UI ชุดนี้เชื่อมต่อกับ REST API มาตรฐานของ Kotchasan/Now.js ดังนี้:

### 1) ข้อมูลผู้ใช้และสถิติ
- `GET api/index/auth/me` : ดึงข้อมูลโปรไฟล์ผู้ใช้ปัจจุบัน (ชื่อ, รูป Avatar, สิทธิ์)
- `GET api/booking/statistics` : สรุปจำนวนการจอง (Pending, Returned, Approved)

### 2) การจองห้อง (Booking Form & Live Data)
- `GET api/booking/booking/get` : ดึงตัวเลือกห้อง (`options.room_id`), การใช้งาน (`options.use`), อุปกรณ์เสริม (`options.accessories`)
- `POST api/booking/booking/save` : ส่งข้อมูลการจองห้อง (รองรับทั้งแบบรายวันและเลือกวันในสัปดาห์)
- `GET api/booking/calendar` : ดึง Event การจองทั้งหมดสำหรับแสดงผลบน EventCalendar

### 3) ประวัติการจอง (My Bookings)
- `GET api/booking/mybookings` : ดึงรายการประวัติการจองของตนเอง (Server-side Pagination / Sort)
- `POST api/booking/mybookings/action` : การสั่งยกเลิกคำขอจองห้อง (`action: cancel`)

### 4) แคตตาล็อกห้อง (Room Catalog)
- `GET api/booking/catalog/lists` : ดึงรายการห้องทั้งหมดพร้อมรูปภาพ อุปกรณ์ และสถานะความพร้อม

---

## 4. สิ่งที่ฝั่งแอดมิน/หลังบ้านพัฒนาต่อได้ทันทีโดยไม่ชนกัน

- ไฟล์ในชุดส่งมอบนี้ **ไม่มีการแตะต้องหรือแก้ไขหน้าแอดมิน** ใดๆ:
  - `templates/booking/*-admin.html`
  - `templates/booking/approvals.html`
  - `templates/booking/review.html`
  - `templates/booking/rooms.html`
  - `templates/booking/settings.html`
  - `templates/users.html`
  - `settings/config.php`
- ฝั่งหลังบ้านสามารถนำโฟลเดอร์ `ui-handoff/` ไปทับหรือวางในโปรเจกต์หลักได้ทันที โดยไฟล์จะซิงค์เฉพาะ UI ฝั่งผู้ใช้งาน 4 หน้าหลัก และไม่กระทบโครงสร้างของแอดมินครับ

