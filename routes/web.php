<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

function statusTahunBuku(int $tahun): string
{
    if ($tahun >= 2023) {
        return 'Baru';
    }

    return 'Lama';
}

function bersihkanInputBuku(Request $request): array
{
    return [
        'kode_buku' => strip_tags(trim((string) $request->input('kode_buku'))),
        'judul' => strip_tags(trim((string) $request->input('judul'))),
        'isbn' => strip_tags(trim((string) $request->input('isbn'))),
        'tahun_terbit' => trim((string) $request->input('tahun_terbit')),
        'kategori_buku_id' => trim((string) $request->input('kategori_buku_id')),
    ];
}

function validatorBuku(array $data): \Illuminate\Contracts\Validation\Validator
{
    return Validator::make($data, [
        'kode_buku' => ['required', 'regex:/^[A-Za-z0-9]{10}$/'],
        'judul' => ['required', 'min:3', 'max:100'],
        'isbn' => ['required', 'regex:/^[0-9]{13}$/'],
        'tahun_terbit' => ['required', 'integer', 'between:2000,2026'],
        'kategori_buku_id' => ['required', 'integer'],
    ], [
        'kode_buku.required' => 'Kode buku wajib diisi.',
        'kode_buku.regex' => 'Kode buku harus tepat 10 karakter huruf/angka.',
        'judul.required' => 'Judul wajib diisi.',
        'judul.min' => 'Judul minimal 3 karakter.',
        'judul.max' => 'Judul maksimal 100 karakter.',
        'isbn.required' => 'ISBN wajib diisi.',
        'isbn.regex' => 'ISBN harus tepat 13 digit angka.',
        'tahun_terbit.required' => 'Tahun terbit wajib diisi.',
        'tahun_terbit.integer' => 'Tahun terbit harus berupa angka.',
        'tahun_terbit.between' => 'Tahun terbit harus antara 2000 dan 2026.',
        'kategori_buku_id.required' => 'Kategori wajib dipilih.',
        'kategori_buku_id.integer' => 'Kategori tidak valid.',
    ]);
}

function kategoriAda(\PDO $pdo, int $id): bool
{
    $cek = $pdo->prepare('SELECT COUNT(*) FROM kategori_buku WHERE id = :id');
    $cek->execute(['id' => $id]);

    return (int) $cek->fetchColumn() > 0;
}

Route::get('/', function () {
    return view('welcome');
});

Route::get('/buku', function (Request $request) {
    $kataKunci = trim((string) $request->query('q', ''));
    $kategoriId = $request->query('kategori_buku_id', '');

    try {
        $pdo = DB::connection()->getPdo();

        $sql = 'SELECT b.id, b.kode_buku, b.judul, b.isbn, b.tahun_terbit,
                       b.kategori_buku_id, k.nama_kategori
                FROM buku AS b
                JOIN kategori_buku AS k
                  ON k.id = b.kategori_buku_id
                WHERE 1 = 1';
        $parameter = [];

        if ($kataKunci !== '') {
            $sql .= ' AND (b.kode_buku LIKE :kata_kunci_kode
                      OR b.judul LIKE :kata_kunci_judul
                      OR b.isbn LIKE :kata_kunci_isbn)';
            $nilaiPencarian = '%' . $kataKunci . '%';
            $parameter['kata_kunci_kode'] = $nilaiPencarian;
            $parameter['kata_kunci_judul'] = $nilaiPencarian;
            $parameter['kata_kunci_isbn'] = $nilaiPencarian;
        }

        if ($kategoriId !== '') {
            $sql .= ' AND b.kategori_buku_id = :kategori_buku_id';
            $parameter['kategori_buku_id'] = (int) $kategoriId;
        }

        $sql .= ' ORDER BY b.kode_buku';

        $ambilBuku = $pdo->prepare($sql);
        $ambilBuku->execute($parameter);
        $daftarBuku = $ambilBuku->fetchAll(\PDO::FETCH_ASSOC);

        $ambilKategori = $pdo->prepare(
            'SELECT id, nama_kategori FROM kategori_buku ORDER BY nama_kategori'
        );
        $ambilKategori->execute();
        $daftarKategori = $ambilKategori->fetchAll(\PDO::FETCH_ASSOC);

        return view('buku', compact(
            'daftarBuku',
            'daftarKategori',
            'kataKunci',
            'kategoriId'
        ));
    } catch (\Throwable $error) {
        report($error);

        return response(
            'Data tidak dapat dibaca. Periksa koneksi dan query.',
            500
        );
    }
})->name('buku.index');

Route::post('/buku', function (Request $request) {
    $data = bersihkanInputBuku($request);
    $validator = validatorBuku($data);

    if ($validator->fails()) {
        return back()->withErrors($validator)->withInput($data);
    }

    try {
        $pdo = DB::connection()->getPdo();

        if (!kategoriAda($pdo, (int) $data['kategori_buku_id'])) {
            return back()->withInput($data)->with(
                'gagal',
                'Kategori yang dipilih tidak tersedia.'
            );
        }

        $cek = $pdo->prepare(
            'SELECT COUNT(*) FROM buku
             WHERE kode_buku = :kode_buku OR isbn = :isbn'
        );
        $cek->execute([
            'kode_buku' => $data['kode_buku'],
            'isbn' => $data['isbn'],
        ]);

        if ((int) $cek->fetchColumn() > 0) {
            return back()->withInput($data)->with(
                'gagal',
                'Kode buku atau ISBN sudah digunakan.'
            );
        }

        $simpan = $pdo->prepare(
            'INSERT INTO buku
             (kode_buku, judul, isbn, tahun_terbit, kategori_buku_id)
             VALUES
             (:kode_buku, :judul, :isbn, :tahun_terbit, :kategori_buku_id)'
        );
        $simpan->execute([
            'kode_buku' => $data['kode_buku'],
            'judul' => $data['judul'],
            'isbn' => $data['isbn'],
            'tahun_terbit' => (int) $data['tahun_terbit'],
            'kategori_buku_id' => (int) $data['kategori_buku_id'],
        ]);

        return redirect()->route('buku.index')->with(
            'berhasil',
            'Data buku berhasil ditambahkan.'
        );
    } catch (\Throwable $error) {
        report($error);

        return back()->withInput($data)->with(
            'gagal',
            'Data gagal disimpan. Periksa koneksi dan data.'
        );
    }
})->name('buku.store');

Route::get('/buku/{id}/edit', function (int $id) {
    try {
        $pdo = DB::connection()->getPdo();

        $ambilBuku = $pdo->prepare(
            'SELECT id, kode_buku, judul, isbn, tahun_terbit, kategori_buku_id
             FROM buku WHERE id = :id'
        );
        $ambilBuku->execute(['id' => $id]);
        $buku = $ambilBuku->fetch(\PDO::FETCH_ASSOC);

        if (!$buku) {
            return redirect()->route('buku.index')->with(
                'gagal',
                'Data buku tidak ditemukan.'
            );
        }

        $ambilKategori = $pdo->prepare(
            'SELECT id, nama_kategori FROM kategori_buku ORDER BY nama_kategori'
        );
        $ambilKategori->execute();
        $daftarKategori = $ambilKategori->fetchAll(\PDO::FETCH_ASSOC);

        return view('buku-edit', compact('buku', 'daftarKategori'));
    } catch (\Throwable $error) {
        report($error);

        return redirect()->route('buku.index')->with(
            'gagal',
            'Data gagal dibuka untuk diubah.'
        );
    }
})->name('buku.edit');

Route::put('/buku/{id}', function (Request $request, int $id) {
    $data = bersihkanInputBuku($request);
    $validator = validatorBuku($data);

    if ($validator->fails()) {
        return redirect()->route('buku.edit', $id)
            ->withErrors($validator)
            ->withInput($data);
    }

    try {
        $pdo = DB::connection()->getPdo();

        if (!kategoriAda($pdo, (int) $data['kategori_buku_id'])) {
            return redirect()->route('buku.edit', $id)->withInput($data)->with(
                'gagal',
                'Kategori yang dipilih tidak tersedia.'
            );
        }

        $cek = $pdo->prepare(
            'SELECT COUNT(*) FROM buku
             WHERE (kode_buku = :kode_buku OR isbn = :isbn)
               AND id <> :id'
        );
        $cek->execute([
            'kode_buku' => $data['kode_buku'],
            'isbn' => $data['isbn'],
            'id' => $id,
        ]);

        if ((int) $cek->fetchColumn() > 0) {
            return redirect()->route('buku.edit', $id)->withInput($data)->with(
                'gagal',
                'Kode buku atau ISBN sudah digunakan buku lain.'
            );
        }

        $ubah = $pdo->prepare(
            'UPDATE buku
             SET kode_buku = :kode_buku, judul = :judul, isbn = :isbn,
                 tahun_terbit = :tahun_terbit,
                 kategori_buku_id = :kategori_buku_id
             WHERE id = :id'
        );
        $ubah->execute([
            'kode_buku' => $data['kode_buku'],
            'judul' => $data['judul'],
            'isbn' => $data['isbn'],
            'tahun_terbit' => (int) $data['tahun_terbit'],
            'kategori_buku_id' => (int) $data['kategori_buku_id'],
            'id' => $id,
        ]);

        return redirect()->route('buku.index')->with(
            'berhasil',
            'Data buku berhasil diperbarui.'
        );
    } catch (\Throwable $error) {
        report($error);

        return redirect()->route('buku.edit', $id)->withInput($data)->with(
            'gagal',
            'Data gagal diperbarui. Periksa koneksi dan data.'
        );
    }
})->name('buku.update');

Route::delete('/buku/{id}', function (int $id) {
    try {
        $pdo = DB::connection()->getPdo();

        $hapus = $pdo->prepare('DELETE FROM buku WHERE id = :id');
        $hapus->execute(['id' => $id]);

        if ($hapus->rowCount() === 0) {
            return redirect()->route('buku.index')->with(
                'gagal',
                'Data buku tidak ditemukan.'
            );
        }

        return redirect()->route('buku.index')->with(
            'berhasil',
            'Data buku berhasil dihapus.'
        );
    } catch (\Throwable $error) {
        report($error);

        return redirect()->route('buku.index')->with(
            'gagal',
            'Data gagal dihapus. Periksa relasi atau koneksi.'
        );
    }
})->name('buku.destroy');
