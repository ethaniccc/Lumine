mod model;
mod packets;
#[cfg(test)]
mod test;
pub mod varint;
use crate::model::{Vec2, Vec3, UUID};
use crate::varint::{ReadProtocolVarIntExt, WriteProtocolVarIntExt};
use byteorder::ReadBytesExt;
use derive_more::*;
pub use packets::*;
use std::convert::TryFrom;
use std::io::{Cursor, Error, ErrorKind};
use std::ops;

/// This is mostly stolen from sofe because its really useful.

/// An error occurred when decoding.
#[derive(Debug)]
pub enum DecodeError {
    UnexpectedEof,
    OutOfRange,
    InvalidUtf8,
    InvalidData,
    Unsupported,
}

impl From<std::io::Error> for DecodeError {
    fn from(err: Error) -> Self {
        match err.kind() {
            ErrorKind::InvalidData => DecodeError::InvalidData,
            ErrorKind::UnexpectedEof => DecodeError::UnexpectedEof,
            _ => DecodeError::Unsupported,
        }
    }
}

pub type Result<T = (), E = DecodeError> = std::result::Result<T, E>;

/// Allows the type to be encoded/decoded using mcpe network binary format.
pub trait CanIo: Sized {
    fn write(&self, vec: &mut Vec<u8>);

    fn read(src: &[u8], offset: &mut usize) -> Result<Self>;
}

/// Binary representation of a bool.
impl CanIo for bool {
    fn write(&self, vec: &mut Vec<u8>) {
        vec.push(if *self { 1 } else { 0 });
    }

    fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
        match src.get(postinc(offset, 1)) {
            Some(0) => Ok(false),
            Some(1) => Ok(true),
            Some(_) => Err(DecodeError::OutOfRange),
            _ => Err(DecodeError::UnexpectedEof),
        }
    }
}

macro_rules! impl_primitive {
    ($ty:ty, $size:literal) => {
        /// Binary representation in big-endian.
        ///
        /// Wrap the type with `Little` to encode in little-endian.
        impl CanIo for $ty {
            fn write(&self, vec: &mut Vec<u8>) {
                vec.extend_from_slice(&self.to_be_bytes());
            }

            fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
                let range = range_inc(offset, $size);
                match src.get(range) {
                    Some(slice) => {
                        let mut dest = [0u8; $size];
                        dest.copy_from_slice(slice);
                        Ok(<$ty>::from_be_bytes(dest))
                    }
                    None => Err(DecodeError::UnexpectedEof),
                }
            }
        }

        /// Binary representation in little-endian.
        impl CanIo for Little<$ty> {
            fn write(&self, vec: &mut Vec<u8>) {
                vec.extend_from_slice(&self.0.to_le_bytes());
            }

            fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
                let range = range_inc(offset, $size);
                match src.get(range) {
                    Some(slice) => {
                        let mut dest = [0u8; $size];
                        dest.copy_from_slice(slice);
                        Ok(Self(<$ty>::from_le_bytes(dest)))
                    }
                    None => Err(DecodeError::UnexpectedEof),
                }
            }
        }
    };
}

impl_primitive!(u8, 1);
impl_primitive!(u16, 2);
impl_primitive!(u32, 4);
impl_primitive!(u64, 8);
impl_primitive!(i8, 1);
impl_primitive!(i16, 2);
impl_primitive!(i32, 4);
impl_primitive!(i64, 8);
impl_primitive!(f32, 4);
impl_primitive!(f64, 8);

/// Encodes a string using a u16 prefix indicating the length, followed by the characters encoded
/// in UTF-8.
impl CanIo for String {
    fn write(&self, vec: &mut Vec<u8>) {
        vec.write_var_u32((self.len()) as u32).unwrap();
        vec.extend_from_slice(self.as_bytes());
    }

    fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
        let mut src = Cursor::new(src);
        src.set_position(*offset as u64);
        let len = src.read_var_u32()?;
        let mut str = String::with_capacity(len.0 as usize);
        for _ in 0..len.0 {
            str.push(src.read_u8()? as char)
        }
        *offset = src.position() as usize;
        Ok(str)
    }
}

impl CanIo for UUID {
    fn write(&self, vec: &mut Vec<u8>) {
        Little(self.parts[1]).write(vec);
        Little(self.parts[0]).write(vec);
        Little(self.parts[3]).write(vec);
        Little(self.parts[2]).write(vec);
    }

    fn read(src: &[u8], _: &mut usize) -> Result<Self> {
        Ok(Self::try_from(&mut Cursor::new(src))?)
    }
}

impl CanIo for Vec3 {
    fn write(&self, vec: &mut Vec<u8>) {
        Little(self.x).write(vec);
        Little(self.y).write(vec);
        Little(self.z).write(vec);
    }

    fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
        let x = Little::<f32>::read(src, offset)?;
        let y = Little::<f32>::read(src, offset)?;
        let z = Little::<f32>::read(src, offset)?;
        Ok(Self {
            x: x.inner(),
            y: y.inner(),
            z: z.inner(),
        })
    }
}

impl CanIo for Vec2 {
    fn write(&self, vec: &mut Vec<u8>) {
        Little(self.x).write(vec);
        Little(self.y).write(vec);
    }

    fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
        let x = Little::<f32>::read(src, offset)?;
        let y = Little::<f32>::read(src, offset)?;
        Ok(Self {
            x: x.inner(),
            y: y.inner(),
        })
    }
}

impl CanIo for Vec<u8> {
    fn write(&self, vec: &mut Vec<u8>) {
        vec.write_var_u32((self.len()) as u32).unwrap();
        vec.extend(self);
    }

    fn read(src: &[u8], offset: &mut usize) -> Result<Self> {
        let mut src = Cursor::new(src);
        src.set_position(*offset as u64);
        let len = src.read_var_u32()?;
        let mut vec = Vec::with_capacity(len.0 as usize);
        for _ in 0..len.0 {
            vec.push(src.read_u8()?);
        }
        *offset = src.position() as usize;
        Ok(vec)
    }
}

fn postinc<T>(lvalue: &mut T, rvalue: T) -> T
where
    T: ops::AddAssign<T> + Clone,
{
    let clone = lvalue.clone();
    *lvalue += rvalue;
    clone
}

fn range_inc<T>(lvalue: &mut T, rvalue: T) -> ops::Range<T>
where
    T: ops::AddAssign<T> + Clone,
{
    let from = lvalue.clone();
    *lvalue += rvalue;
    let to = lvalue.clone();
    from..to
}

/// A wrapper of the primitive types, encoded in little-endian instead of big-endian.
#[derive(Clone, Copy, Debug, Default, From, PartialEq, Eq, PartialOrd, Ord)]
pub struct Little<T: Copy + Default>(pub T);

impl<T: Copy + Default> Little<T> {
    #[inline]
    pub fn inner(self) -> T {
        self.0
    }
}
