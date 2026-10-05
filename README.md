# PHP Flat-file Blog

PHP ve JSON dosyalarıyla çalışan, veritabanı gerektirmeyen blog uygulaması.

## Özellikler

- Üyelik, oturum açma ve ilk yönetici hesabı kurulumu
- Yazı ve sayfalar için taslak/yayında durumları ve herkese açık, yalnızca üyelere açık veya bağlantıya sahip olanların görebileceği gizlilik seçenekleri
- Yazılara bağlı yorumlar; yönetici onayı, yorumları herkese ya da yalnızca üyelere gösterme ve yorumları yönetme
- Yazılara eklenebilen sınırsız anket ve sayfalara bağlanabilen sınırsız iletişim formu
- İletişim formu mesajları için yönetim panelinde gelen kutusu
- Site adı, açıklaması, üyelik ve yorum tercihleri ile değiştirilebilir yönetim paneli adresi
- JSON dosyalarıyla kilitli ve atomik kayıt, CSRF koruması, parola hash'leme ve kaçışlanmış HTML çıktısı

## Kurulum

1. PHP 8.1 veya üzeri bulunan bir web sunucusunda proje dizinini yayınlayın. Apache kullanıyorsanız `.htaccess` dosyasındaki yeniden yazma kuralının etkin olduğundan emin olun.
2. PHP işleminin `storage/` dizinine yazma izni olmalıdır. Bu dizin uygulama tarafından oluşturulabilir; depolama dosyalarına web sunucusu üzerinden erişim engellenmelidir. Apache yapılandırması için `storage/.htaccess` eklenmiştir. Nginx kullanıyorsanız `storage/` yoluna doğrudan erişimi ayrıca engelleyin.
3. Siteyi açıp `/setup` adresinde ilk yönetici hesabını oluşturun. Kurulum, ilk hesap oluşturulduktan sonra otomatik olarak kapanır.
4. Yönetici adresi varsayılan olarak `/admin` olur; bunu yönetim panelindeki **Settings** sayfasından değiştirebilirsiniz.

## İçerik yönetimi

Yönetim panelinden yazı ve sayfaları oluşturun. Bir yazı yayımlandığında ankete bağlanabilir; bir iletişim formu oluşturup sayfa düzenleyicisinden sayfaya bağlayabilirsiniz. Gelen başvurular panelin **Inbox** bölümüne kaydedilir. Varsayılan yorum ayarı yorumları onaya alır; gizlilik ayarları **Settings** sayfasındadır.

İçerik `storage/blog.json` içinde saklanır. Düzenli yedek almak için bu dosyanın kopyasını güvenli bir yerde tutun. Uygulamanın çalışması için MySQL veya başka bir veritabanı gerekmez.
