use std::io::{self, Result, ErrorKind, Error};
use byteorder::{ReadBytesExt, WriteBytesExt};

pub trait ReadProtocolVarIntExt: io::Read {

    #[inline]
    fn read_var_i64(&mut self) -> Result<(i64, usize)> {
        let (unsigned_var, len) = self.read_var_u64()?;
        let mut signed = (unsigned_var >> 1) as i64;
        if unsigned_var&1 != 0 {
            signed = !signed
        };
        Ok((signed, len))
    }

    #[inline]
    fn read_var_u64(&mut self) -> Result<(u64, usize)> {
        let mut uvar: u64 = 0;
        let mut i: u64 = 0;
        while i < 70 {
            let b = self.read_u8()?;
            uvar |= ((b&0x7f) as u64) << i;
            if b&0x80 == 0 {
                return Ok((uvar, (i / 7) as usize + 1));
            }
            i += 7
        };
        Err(Error::new(ErrorKind::InvalidData, "var_u64 didn't terminate after 10 bytes."))
    }

    #[inline]
    fn read_var_i32(&mut self) -> Result<(i32, usize)> {
        let (unsigned_var, len) = self.read_var_u32()?;
        let mut signed = (unsigned_var >> 1) as i32;
        if unsigned_var&1 != 0 {
            signed = !signed
        }
        Ok((signed, len))
    }

    #[inline]
    fn read_var_u32(&mut self) -> Result<(u32, usize)> {
        let mut uvar: u32 = 0;
        let mut i: u32 = 0;
        while i < 35 {
            let b = self.read_u8()?;
            uvar |= ((b&0x7f) as u32) << i;
            if b&0x80 == 0 {
                return Ok((uvar, (i / 7) as usize + 1));
            }
            i += 7
        };
        Err(Error::new(ErrorKind::InvalidData, "var_u32 didn't terminate after 5 bytes."))
    }
}

impl<R: io::Read + ?Sized> ReadProtocolVarIntExt for R {}

pub trait WriteProtocolVarIntExt: io::Write {
    #[inline]
    fn write_var_u64(&mut self, v: u64) -> Result<usize> {
        let mut uv = v;
        let mut i = 0;
        while uv >= 0x80 {
            self.write_u8((uv as u8) | 0x80)?;
            uv >>= 7;
            i += 1
        };
        self.write_u8(uv as u8)?;
        Ok(i+1)
    }

    #[inline]
    fn write_var_i64(&mut self, v: i64) -> Result<usize> {
        let mut unsigned_var = (v << 1) as u64;
        if v < 0 {
            unsigned_var = !unsigned_var
        }
        self.write_var_u64(unsigned_var)
    }

    #[inline]
    fn write_var_u32(&mut self, v: u32) -> Result<usize> {
        let mut uv = v;
        let mut i = 0;
        while uv >= 0x80 {
            self.write_u8((uv as u8) | 0x80)?;
            uv >>= 7;
            i += 1
        };
        self.write_u8(uv as u8)?;
        Ok(i+1)
    }

    #[inline]
    fn write_var_i32(&mut self, v: i32) -> Result<usize> {
        let mut unsigned_var = (v << 1) as u32;
        if v < 0 {
            unsigned_var = !unsigned_var
        }
        self.write_var_u32(unsigned_var)
    }
}

impl<W: io::Write + ?Sized> WriteProtocolVarIntExt for W {}