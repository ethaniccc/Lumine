use crate::CanIo;
use crate::varint::{WriteProtocolVarIntExt, ReadProtocolVarIntExt};
use std::io::Cursor;

#[derive(Debug, Clone)]
pub struct SetTime {
    pub time: i32
}

impl CanIo for SetTime {
    fn write(&self, vec: &mut Vec<u8>) {
        vec.write_var_i32(self.time).unwrap();
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let mut src = Cursor::new(src);
        src.set_position(*offset as u64);
        let (time, _) = src.read_var_i32()?;
        *offset = src.position() as usize;
        Ok(Self {
            time
        })
    }
}

