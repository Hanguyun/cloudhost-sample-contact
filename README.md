# CloudHost 문의 양식 샘플

`index.php`와 `style.css`를 샘플 배포 저장소의 루트에 둡니다. PHP는 CloudHost가 웹 VM에 설치한 DB 설정과 CA로 해당 호스팅의 MariaDB에 TLS로 접속합니다. 저장소에 DB 설정 파일이나 자격 증명을 넣을 필요가 없습니다.

웹을 공개한 뒤 테스트 이름, `demo@example.com`, 문의 내용을 입력하고 **문의 접수**를 누릅니다. 처음 접수할 때 `cloudhost_sample_inquiries` 테이블을 만들고 문의를 저장합니다. 호스팅 소유자는 DB 콘솔에서 최근 접수 결과를 확인할 수 있습니다.

```sql
SELECT id, name, email, message, created_at
FROM cloudhost_sample_inquiries
ORDER BY id DESC
LIMIT 5;
```
