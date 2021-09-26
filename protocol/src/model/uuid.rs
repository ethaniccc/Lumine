use byteorder::{ReadBytesExt, LittleEndian};
use std::io::{Cursor, ErrorKind, Error};
use std::convert::TryFrom;

#[derive(Debug)]
pub struct UUID {
    pub parts: Vec<i32>,
    pub version: Option<i32>
}

impl UUID {
    pub fn new(part1: i32, part2: i32, part3: i32, part4: i32, version: Option<i32>) -> Self {
        Self {
            parts: vec![part1, part2, part3, part4],
            version
        }
    }
}

impl PartialEq for UUID {
    fn eq(&self, other: &Self) -> bool {
        //If the parts are the same and version are the same.
        self.version == other.version && self.parts == other.parts
    }
}

impl TryFrom<String> for UUID {
    type Error = std::io::Error;

    fn try_from(str: String) -> Result<Self, Self::Error> {
        let bin = hex::decode(str.replace("-", "")).expect("Invalid string uuid.");
        println!("{}", bin.len());
        UUID::try_from(&mut Cursor::new(bin.as_slice()))
    }
}

impl TryFrom<&mut Cursor<&[u8]>> for UUID {
    type Error = std::io::Error;

    fn try_from(buf: &mut Cursor<&[u8]>) -> Result<Self, Self::Error> {
        if buf.clone().into_inner().len() != 16 {
            return Err(Error::new(ErrorKind::InvalidData, "A valid UUID must only be 16 bytes in length."));
        }
        let part1 = buf.read_i32::<LittleEndian>()?;
        let part0 = buf.read_i32::<LittleEndian>()?;
        let part3 = buf.read_i32::<LittleEndian>()?;
        let part2 = buf.read_i32::<LittleEndian>()?;
        Ok(UUID::new(part0, part1, part2, part3, Option::from(3)))
    }
}
