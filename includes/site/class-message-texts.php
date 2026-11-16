<?php
/**
 * The checkout's customer messages (decline, processing, unavailable …) and the "Or Pay with Card"
 * separator, per language. English is the plugin's own text (Shortcode::messages()); a language
 * missing here shows English.
 *
 * The language is found as for the email field (Email_Texts::resolve()): the template's `locale`
 * attribute, else the page's WPML language, else the WordPress site language.
 *
 * Sites change or add texts with the `cellon_payment_collection_messages` filter (all keys) or the
 * template's `separator` attribute.
 *
 * @package Cellon\PaymentCollection
 */

namespace Cellon\PaymentCollection\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Message_Texts {

	/** language (an Email_Texts key) => message key => text. "separator" is the line above the card form. */
	const TEXTS = [
		'ja' => [
			'next_gateway' => 'お支払いを処理できませんでした。お支払いを完了するには、もう一度カード情報を入力してください。',
			'retry_same'   => 'お支払いが承認されませんでした。カード情報をご確認いただくか、別のカードをご利用ください。',
			'stop'         => 'お支払いが承認されませんでした。カード会社にお問い合わせいただくか、別のカードをご利用ください。',
			'verify'       => 'お支払い状況を確認しています。再度お支払いはしないでください。',
			'unavailable'  => '現在お支払いをご利用いただけません。しばらくしてから再度お試しください。',
			'failed'       => 'お支払いを完了できませんでした。後ほど再度お試しいただくか、サポートまでお問い合わせください。',
			'verify_again' => '再確認する',
			'pay_again'    => '請求されていません：もう一度支払う',
			'processing'   => 'お支払いを処理しています…しばらくお待ちください。',
			'busy'         => 'お支払いを処理中です。しばらくお待ちください。',
			'expired'      => 'このお支払い画面の有効期限が切れました。ページを再読み込みしてください。',
			'separator'    => 'またはカードで支払う',
		],
		'ko' => [
			'next_gateway' => '결제를 처리할 수 없습니다. 결제를 완료하려면 카드 정보를 다시 입력해 주세요.',
			'retry_same'   => '결제가 거절되었습니다. 카드 정보를 확인하시거나 다른 카드를 사용해 주세요.',
			'stop'         => '결제가 거절되었습니다. 카드사에 문의하시거나 다른 카드를 사용해 주세요.',
			'verify'       => '결제 상태를 확인하고 있습니다. 다시 결제하지 마세요.',
			'unavailable'  => '지금은 결제를 이용할 수 없습니다. 잠시 후 다시 시도해 주세요.',
			'failed'       => '결제를 완료할 수 없습니다. 나중에 다시 시도하시거나 고객센터에 문의해 주세요.',
			'verify_again' => '다시 확인',
			'pay_again'    => '청구되지 않았습니다: 다시 결제',
			'processing'   => '결제를 처리하고 있습니다… 잠시 기다려 주세요.',
			'busy'         => '결제를 처리하고 있습니다. 잠시 기다려 주세요.',
			'expired'      => '결제 화면이 만료되었습니다. 페이지를 새로고침해 주세요.',
			'separator'    => '또는 카드로 결제',
		],
		'zh' => [
			'next_gateway' => '您的付款无法处理。请重新输入银行卡信息以完成付款。',
			'retry_same'   => '您的付款被拒绝。请检查银行卡信息或使用其他银行卡。',
			'stop'         => '您的付款被拒绝。请联系您的银行或使用其他银行卡。',
			'verify'       => '我们正在确认您的付款状态。请勿重复付款。',
			'unavailable'  => '目前无法付款。请几分钟后再试。',
			'failed'       => '您的付款未能完成。请稍后再试或联系客服。',
			'verify_again' => '再次确认',
			'pay_again'    => '未扣款：重新付款',
			'processing'   => '正在处理您的付款…请稍候。',
			'busy'         => '正在处理付款，请稍候。',
			'expired'      => '此付款页面已过期。请刷新页面。',
			'separator'    => '或使用银行卡支付',
		],
		'id' => [
			'next_gateway' => 'Pembayaran Anda tidak dapat diproses. Silakan masukkan kembali detail kartu Anda untuk menyelesaikan pembayaran.',
			'retry_same'   => 'Pembayaran Anda ditolak. Silakan periksa detail kartu Anda atau gunakan kartu lain.',
			'stop'         => 'Pembayaran Anda ditolak. Silakan hubungi bank Anda atau gunakan kartu lain.',
			'verify'       => 'Kami sedang memeriksa status pembayaran Anda. Jangan membayar lagi.',
			'unavailable'  => 'Pembayaran saat ini tidak tersedia. Silakan coba lagi dalam beberapa menit.',
			'failed'       => 'Pembayaran Anda tidak dapat diselesaikan. Silakan coba lagi nanti atau hubungi dukungan.',
			'verify_again' => 'Periksa lagi',
			'pay_again'    => 'Tidak ditagih: bayar lagi',
			'processing'   => 'Memproses pembayaran Anda… Mohon tunggu.',
			'busy'         => 'Pembayaran sedang diproses. Mohon tunggu.',
			'expired'      => 'Halaman pembayaran ini telah kedaluwarsa. Silakan muat ulang halaman.',
			'separator'    => 'Atau Bayar dengan Kartu',
		],
		'vi' => [
			'next_gateway' => 'Không thể xử lý thanh toán của bạn. Vui lòng nhập lại thông tin thẻ để hoàn tất thanh toán.',
			'retry_same'   => 'Thanh toán của bạn bị từ chối. Vui lòng kiểm tra thông tin thẻ hoặc sử dụng thẻ khác.',
			'stop'         => 'Thanh toán của bạn bị từ chối. Vui lòng liên hệ ngân hàng hoặc sử dụng thẻ khác.',
			'verify'       => 'Chúng tôi đang kiểm tra trạng thái thanh toán. Vui lòng không thanh toán lại.',
			'unavailable'  => 'Hiện không thể thanh toán. Vui lòng thử lại sau vài phút.',
			'failed'       => 'Không thể hoàn tất thanh toán. Vui lòng thử lại sau hoặc liên hệ bộ phận hỗ trợ.',
			'verify_again' => 'Kiểm tra lại',
			'pay_again'    => 'Chưa bị trừ tiền: thanh toán lại',
			'processing'   => 'Đang xử lý thanh toán… Vui lòng chờ.',
			'busy'         => 'Đang xử lý thanh toán. Vui lòng chờ.',
			'expired'      => 'Trang thanh toán đã hết hạn. Vui lòng tải lại trang.',
			'separator'    => 'Hoặc thanh toán bằng thẻ',
		],
		'th' => [
			'next_gateway' => 'ไม่สามารถดำเนินการชำระเงินของคุณได้ กรุณากรอกข้อมูลบัตรอีกครั้งเพื่อชำระเงินให้เสร็จสมบูรณ์',
			'retry_same'   => 'การชำระเงินของคุณถูกปฏิเสธ กรุณาตรวจสอบข้อมูลบัตรหรือใช้บัตรอื่น',
			'stop'         => 'การชำระเงินของคุณถูกปฏิเสธ กรุณาติดต่อธนาคารของคุณหรือใช้บัตรอื่น',
			'verify'       => 'เรากำลังตรวจสอบสถานะการชำระเงินของคุณ กรุณาอย่าชำระเงินซ้ำ',
			'unavailable'  => 'ขณะนี้ไม่สามารถชำระเงินได้ กรุณาลองใหม่อีกครั้งในอีกสักครู่',
			'failed'       => 'ไม่สามารถชำระเงินให้เสร็จสมบูรณ์ได้ กรุณาลองใหม่ภายหลังหรือติดต่อฝ่ายสนับสนุน',
			'verify_again' => 'ตรวจสอบอีกครั้ง',
			'pay_again'    => 'ยังไม่ถูกเรียกเก็บเงิน: ชำระอีกครั้ง',
			'processing'   => 'กำลังดำเนินการชำระเงิน… กรุณารอสักครู่',
			'busy'         => 'กำลังดำเนินการชำระเงิน กรุณารอสักครู่',
			'expired'      => 'หน้าชำระเงินนี้หมดอายุแล้ว กรุณาโหลดหน้าใหม่',
			'separator'    => 'หรือชำระด้วยบัตร',
		],
		'ms' => [
			'next_gateway' => 'Pembayaran anda tidak dapat diproses. Sila masukkan semula butiran kad anda untuk melengkapkan pembayaran.',
			'retry_same'   => 'Pembayaran anda ditolak. Sila semak butiran kad anda atau gunakan kad lain.',
			'stop'         => 'Pembayaran anda ditolak. Sila hubungi bank anda atau gunakan kad lain.',
			'verify'       => 'Kami sedang menyemak status pembayaran anda. Jangan bayar sekali lagi.',
			'unavailable'  => 'Pembayaran tidak tersedia buat masa ini. Sila cuba lagi dalam beberapa minit.',
			'failed'       => 'Pembayaran anda tidak dapat diselesaikan. Sila cuba lagi kemudian atau hubungi sokongan.',
			'verify_again' => 'Semak semula',
			'pay_again'    => 'Tidak dicaj: bayar semula',
			'processing'   => 'Memproses pembayaran anda… Sila tunggu.',
			'busy'         => 'Pembayaran sedang diproses. Sila tunggu.',
			'expired'      => 'Halaman pembayaran ini telah tamat tempoh. Sila muat semula halaman.',
			'separator'    => 'Atau Bayar dengan Kad',
		],
	];

	/**
	 * Texts of the page's language (empty for English and for languages not in TEXTS).
	 *
	 * @param string $locale The template's `locale` attribute ("ja", "zh", "auto", "").
	 * @return array<string,string>
	 */
	public static function for_locale( string $locale = '' ): array {
		return self::TEXTS[ Email_Texts::resolve( $locale ) ] ?? [];
	}
}
