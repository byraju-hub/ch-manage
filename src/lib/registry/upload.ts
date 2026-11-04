// 큰 파일(등록카드 사진, 심방 녹음)을 서버에 올리기. 호스팅이 한 번에 받는 크기가 128KB 로 제한되어 있어서
// 60KB 조각으로 나눠 보내고, 서버가 돌려주는 올리기 번호로 다음 요청(카드 읽기·심방 분석)을 부른다.
import { auth } from './auth.svelte.ts';

const CHUNK = 60_000;

export const MAX_UPLOAD = 14_000_000;

export async function uploadChunks(file: Blob, onProgress?: (pct: number) => void): Promise<string> {
	if (file.size > MAX_UPLOAD) throw new Error('파일이 너무 큽니다. (14MB 이하)');
	const id = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');
	const buf = new Uint8Array(await file.arrayBuffer());
	for (let seq = 0; seq * CHUNK < buf.length; seq++) {
		const part = buf.subarray(seq * CHUNK, (seq + 1) * CHUNK);
		let bin = '';
		for (let i = 0; i < part.length; i += 8192) bin += String.fromCharCode(...part.subarray(i, i + 8192));
		await auth.api('POST', '/uploads/chunk', { id, seq, data: btoa(bin) });
		onProgress?.(Math.min(100, Math.round(((seq + 1) * CHUNK * 100) / buf.length)));
	}
	return id;
}
